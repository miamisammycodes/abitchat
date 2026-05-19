# Widget Session Tokens + Per-IP Rate Limits

**Date**: 2026-05-19
**Status**: Design (pre-implementation)
**Owner**: sameer@abit.bt
**Related**: `app/Http/Middleware/ValidateWidgetDomain.php`, `app/Http/Controllers/Api/V1/Widget/ChatController.php`, `public/widget/chatbot.js`, `routes/api.php`

---

## Problem

The widget API at `/api/v1/widget/*` today is gated by `ValidateWidgetDomain`, which checks the request's `Origin` header against the tenant's `allowed_domains` allowlist. This works for browsers (which cannot spoof `Origin`), but a scripted attacker (`curl`, headless HTTP client) can trivially set `Origin: customer-site.com` and pass the check. Combined with the `api_key` being visible in the embed script tag, an attacker can scrape a key and abuse the tenant's quota from arbitrary infrastructure.

The fix: every request after a one-time handshake must carry a short-lived bearer token bound to (api_key, Origin, IP) signed by us. Stolen `api_key` alone is insufficient; the attacker would also need to mint a valid token, which requires owning the issuing-time Origin AND IP. Combined with per-IP rate limits, automated abuse becomes expensive enough to deter casual misuse.

---

## Decisions Locked

| Decision | Choice | Rationale |
|---|---|---|
| Token format | JWT signed with `APP_KEY` (HS256) | Stateless, no Redis dependency, ~120 bytes overhead per request. Revocation handled via api_key rotation, not per-token kill (acceptable with short TTL). |
| Token binding | `api_key + Origin + IP` claims | All three checked on every protected request. IP binding pushed up despite mobile-network UX cost — security priority. Auto-refresh on 401 (see "Token lifecycle") makes the cost invisible to users. |
| Token TTL | 30 minutes | Long enough for a typical chat session; short enough to bound damage from leaked tokens. Refresh is cheap. |
| Token storage in widget | In-memory only (no localStorage) | A leaked localStorage token outlives the tab; in-memory dies with the page. Refresh on next page load is acceptable. |
| Issued by | `POST /api/v1/widget/init` | Already exists for session bootstrap. Returns `{session_token, expires_at, ...current config props}`. |
| Failure on token expiry | Widget catches 401, calls `/init` to refresh, retries original request once. | Transparent to user (~500ms pause on network change). If `/init` also fails, surface "Service unavailable" UX. |
| Migration strategy | 7-day dual-accept: server accepts `api_key`-only **and** Bearer-token requests during the window. New chatbot.js sends Bearer; legacy widgets still work. After 7 days, server rejects `api_key`-only requests. | Smooths the rollout for any cached chatbot.js in browsers. Window is logged so we can monitor adoption. |
| Per-IP rate limits (additive to per-key) | Init: 10/min, Message: 30/min, Daily total per IP: **5000** (raised from 1000 — CGNAT, K12 / shared-office NATs, WeWork-style egress routinely have hundreds of users behind one IP; 1000 would fire false positives on legit traffic). Tenant-tunable from v1 via `tenants.settings.widget_ip_daily_cap`. | Anomaly detection still works (sustained > 5000 from one IP is suspicious); legit shared-NAT traffic isn't blocked. |
| 429 response shape | JSON `{error, retry_after}` + `Retry-After` header | Widget can decay its retries and surface a "please wait" UX. |
| Telemetry | Log per-request `{tenant_id, origin, ip_hash, token_issued?, abuse_flag?}` to `widget_audit` channel. `ip_hash` is `SHA-256(ip + APP_KEY)` — deterministic across requests so anomaly detection can correlate over time, salted by APP_KEY so log dumps don't leak raw IPs. | Enables anomaly detection without storing raw IPs verbatim. |
| Backwards compat horizon | Hard cutover at +7 days post-deploy. Old widgets in the wild upgrade on next page load (we serve chatbot.js, browsers don't pin). | Cache-busting handled by our serving headers (already short TTL). |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Visitor page load                                                      │
│   <script src=".../chatbot.js" data-chatbot-key="abc...">                │
│                                                                         │
│   chatbot.js:                                                           │
│     POST /api/v1/widget/init  { api_key }                               │
│        ← { session_token: "eyJ...", expires_at, config }                │
│     [token kept in-memory]                                              │
└───────────────────────────────────────┬─────────────────────────────────┘
                                        │
                  Visitor sends a chat message
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  POST /api/v1/widget/message                                            │
│    Authorization: Bearer <session_token>                                │
│    body: { conversation_id, content }                                   │
│                                                                         │
│  Server:                                                                │
│    1. ValidateWidgetDomain (existing) — Origin check, CORS              │
│    2. RequireWidgetSessionToken (NEW) — verifies JWT:                   │
│          - signature matches APP_KEY                                    │
│          - not expired                                                  │
│          - claim `aud` matches today's Origin                           │
│          - claim `ip` matches today's request IP                        │
│          - claim `sub` matches resolved tenant api_key                  │
│    3. ThrottlePerIp (NEW) — per-IP bucket on top of throttle:widget     │
│    4. CheckLimits:tokens (existing)                                     │
│    5. ChatController::sendMessage                                       │
│                                                                         │
│  401 path:                                                              │
│    Any check 2 failure → 401 { error: "session_expired" }                │
│    Widget catches, calls /init, retries.                                │
└─────────────────────────────────────────────────────────────────────────┘

Dual-accept window (first 7 days post-deploy):
  If no Bearer token: middleware falls through to legacy api_key check
  (existing ValidateWidgetDomain behavior), logs `legacy_request: true`.
  After window: missing Bearer → 401 hard.
```

---

## Components

### `App\Services\Widget\SessionTokenService` (new)

- **Purpose**: mint + verify JWTs for widget sessions
- **Public surface**:
  - `mint(Tenant $tenant, string $origin, string $ip): array{token: string, expires_at: int}`
  - `verify(string $token, string $origin, string $ip): Tenant` — returns tenant on success, throws `InvalidSessionTokenException` on any failure (bad signature, expired, claim mismatch)
- **JWT claims**:
  - `iss`: app URL
  - `sub`: SHA-256(tenant api_key + APP_KEY). **Hashing (not tenant_id) is load-bearing**: when a merchant rotates their api_key from the dashboard, all outstanding tokens minted from the prior key fail verification on next use, even within the 30-min TTL window. This gives us a revocation mechanism without per-token state. Do not "simplify" to `tenant_id`.
  - `aud`: canonical origin (e.g., `https://example.com`)
  - `ip`: request IP at issue time (string)
  - `iat`, `exp`: standard timestamps
- **Algorithm**: HS256 with `APP_KEY`
- **Library**: `firebase/php-jwt` (already in `vendor/`)

### `App\Http\Middleware\RequireWidgetSessionToken` (new)

- **Purpose**: enforce Bearer token on all `/widget/*` endpoints except `/init`
- **Behavior**:
  1. Read `Authorization: Bearer <token>` header
  2. During dual-accept window: if missing, fall through to legacy `api_key`-only flow with a `Deprecation` response header and log `legacy_widget_request`
  3. Post-window: if missing, return `401 { error: "session_token_required" }`
  4. Verify via `SessionTokenService::verify(token, origin, ip)`
  5. On verification failure: `401 { error: "session_expired" }`
  6. On success: bind tenant to request as `$request->attributes->set('tenant', $tenant)` (skips re-resolution downstream)

### `App\Http\Middleware\ThrottleWidgetPerIp` (new)

- **Purpose**: per-IP rate limit additive to existing per-key throttle
- **Buckets**:
  - `widget-init`: 10/min per IP, max-burst 3
  - `widget-message`: 30/min per IP, max-burst 5
  - `widget-daily`: 1000/day per IP (rolling 24h)
- **Storage**: Laravel `RateLimiter` facade backed by cache (Redis in prod)
- **Response**: `429 { error: "rate_limited", retry_after: <seconds> }` + `Retry-After` header

### `App\Http\Controllers\Api\V1\Widget\ChatController::init` (modified)

- After existing tenant resolution + config-shape response, mint a session token via `SessionTokenService::mint()`. Include `session_token` and `expires_at` in the response body.
- Legacy clients ignore the new fields and continue using `api_key`-only flow (during dual-accept window).

### `public/widget/chatbot.js` (modified)

- After `/init`: store `session_token` in module-scoped variable, set `axios.defaults.headers.Authorization = 'Bearer ' + token`
- Wrap every API call in a retry interceptor:
  - On 401 with `error: "session_expired"`: call `/init` to refresh, retry original request once
  - On 401 retry failure or any other 4xx: surface to user
  - On 429: respect `Retry-After` header, decay retries
- Token never written to localStorage / sessionStorage (memory-only)
- All requests get `Authorization` header; `api_key` retained in `/init` body only

### `tests/Feature/Api/V1/Widget/SessionTokenTest.php` (new)

Cases:
- Init returns a session_token with correct expiry
- Valid token + matching Origin + matching IP passes middleware
- Tampered token (modified payload) rejected
- Expired token rejected
- Token with mismatched Origin rejected
- Token with mismatched IP rejected
- During dual-accept window, missing token + valid api_key falls through with Deprecation header
- After dual-accept window, missing token returns 401
- Per-IP rate limit fires at threshold
- 429 includes Retry-After header

---

## Data model

No DB changes for v1. JWT is stateless. Per-IP rate limits use the existing `RateLimiter` cache.

Optional future addition (not in v1): `widget_audit_log` table for forensic queries (`{tenant_id, ip_hash, origin, endpoint, status, occurred_at}`).

---

## Validation rules

| Field | Source | Rule |
|---|---|---|
| `Authorization` header | Request header | `Bearer <jwt>` format, base64url-decodable |
| JWT `aud` | Token claim | Must match canonical Origin of current request |
| JWT `ip` | Token claim | Must match `request()->ip()` exactly (after standard proxy header trust) |
| JWT `sub` | Token claim | Must resolve to an active tenant whose api_key still matches |
| JWT `exp` | Token claim | Must be > now |

---

## Failure UX

| Condition | Status | Response | Widget behavior |
|---|---|---|---|
| First request **without** token (dual-accept window) | 200 | Normal + `Deprecation: true` header | Widget upgrades to Bearer flow next page load |
| First request **without** token (post-window) | 401 | `{error: "session_token_required"}` | Widget calls `/init`, retries |
| Request **with** malformed/expired/wrong-claim token (any window) | 401 | `{error: "session_expired"}` | Widget calls `/init`, retries — even during dual-accept |
| Origin/IP claim mismatch | 401 | `{error: "session_expired"}` | Widget calls `/init`, retries (will mint new token bound to current Origin/IP) |
| Per-IP rate limited | 429 | `{error: "rate_limited", retry_after}` | Widget decays + shows "please wait" |
| Per-key rate limited (existing) | 429 | (existing shape) | Same |
| Tenant api_key revoked / rotated | 401 | `{error: "invalid_api_key"}` (from `/init`) | Widget gives up, surfaces "chatbot unavailable" |

**Dual-accept window — token-present-but-invalid is strict, not lenient.** If the Bearer header is present and verification fails (bad signature, expired, claim mismatch), we return 401 regardless of dual-accept state. Rationale: falling through to legacy in this case would neuter the security improvement during the rollout window. The widget's 401-retry handler is self-healing — it re-mints via `/init` and retries — so this surfaces bugs without breaking legitimate users. Only **missing** Bearer header falls through to legacy during the window.

**Multi-tab / refresh behavior.** A visitor opening 3 tabs of the same site mints 3 distinct tokens (one per `/init` call), each with overlapping TTLs. Per-IP and per-key rate-limit buckets are shared across tabs (correct behavior — quota is tenant-/IP-wide, not per-token). Not a leak; called out so a reader doesn't try to "deduplicate" tokens.

---

## Performance

- **JWT verify**: ~50µs (HMAC-SHA256 on a 120-byte payload). No DB hit; tenant resolution cached separately.
- **Per-IP throttle check**: 1 Redis INCR per request (~0.5ms).
- **Token mint**: 1 sign operation per `/init` call. `/init` already happens once per page load.
- **Bandwidth**: Bearer header adds ~150 bytes per request. Negligible.

Hot-path additions: 1 cache write (throttle) + 1 HMAC verify (token). Worst case <1ms server-side per request.

---

## ⚠️ Deployment prerequisites (gating before prod cutover)

The JWT IP-binding and the per-IP rate limit both depend on `request()->ip()` returning the **visitor's IP**, not a proxy's. Today `bootstrap/app.php` does not call `trustProxies(...)` — meaning behind any reverse proxy in prod, every visitor would collapse to the proxy's IP and both defenses would become meaningless.

**Before flipping `WIDGET_SESSION_DUAL_ACCEPT=false`** (cutover at +7 days post-deploy), the deployer MUST verify the prod proxy chain and configure `trustProxies()` correctly. Three common shapes:

| Setup | Configuration |
|---|---|
| Cloudflare → origin | `$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR \| Request::HEADER_X_FORWARDED_HOST \| Request::HEADER_X_FORWARDED_PROTO);` plus trust `CF-Connecting-IP` if used. |
| Single reverse proxy (Forge nginx, simple LB) | `$middleware->trustProxies(at: '*');` for single-server setups; explicit CIDR for multi-server. |
| Direct exposure (no proxy) | No config change needed; `request()->ip()` is already accurate. |

This is also a prerequisite for the existing `throttle:widget` per-key rate limit, which is *already* affected today — all visitors behind the proxy share one rate-limit bucket. Fixing TrustProxies is overdue regardless of this spec.

**Plan Task 0** will be: verify the prod proxy chain (or confirm "no prod yet"), then encode the correct `trustProxies()` call in `bootstrap/app.php`. The plan must not move to Task 1 until this is either resolved or explicitly deferred behind a feature flag.

---

## Migration / rollout

**Day 0 (deploy):**
1. Ship server changes with dual-accept enabled (env: `WIDGET_SESSION_DUAL_ACCEPT=true`)
2. Ship new chatbot.js (auto-distributes; browsers fetch on next page load)
3. Both old (api_key-only) and new (Bearer) widgets work

**Day 1–7 (monitor):**
- Watch `widget_audit` channel for `legacy_request: true` ratio
- Confirm new widgets are minting tokens and refreshing on 401 cleanly
- Inspect any 429 spikes — tune per-IP thresholds if false positives

**Day 7 (cutover):**
- Flip env: `WIDGET_SESSION_DUAL_ACCEPT=false`
- Server now requires Bearer token on all non-`/init` endpoints
- Any unupgraded clients get 401 on next request → their chatbot.js (likely already updated) will call `/init` and recover

**Rollback plan:** flip `WIDGET_SESSION_DUAL_ACCEPT=true` (or re-enable legacy in middleware). Token issuance keeps working; the only difference is enforcement is paused.

---

## Accepted v1 risks

- **Replay within token TTL.** An attacker who exfiltrates a valid token (XSS, malicious browser extension, compromised customer site) can replay requests against the same Origin+IP for up to 30 minutes. Mitigated by short TTL, Origin+IP binding (replay must come from the visitor's exact network), and the per-IP rate limits which bound the damage even if all three are satisfied. The full mitigation is HMAC request signing with per-request nonces — deferred to v2 because the TTL/binding combo already raises the bar significantly. If a higher-trust use case appears (PCI, financial), prioritize then.
- **No token revocation list.** api_key rotation is the kill switch (the JWT `sub` claim hashes the api_key, so rotation invalidates all outstanding tokens for that tenant). Per-token revocation would require stateful storage.

## Test-environment note

In Pest/PHPUnit, `request()->ip()` defaults to `127.0.0.1`. IP-binding tests must explicitly diverge issue-time and verify-time IPs to actually exercise the mismatch path:

```php
$token = $service->mint($tenant, 'https://example.com', '203.0.113.10');
$this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
     ->withHeaders(['Authorization' => "Bearer {$token}"])
     ->postJson('/api/v1/widget/message', ...)
     ->assertStatus(401);
```

If a test relies on Laravel's default `127.0.0.1` for both sides, the IP-binding check is silently a no-op. Every test that exercises the IP-mismatch path must set REMOTE_ADDR explicitly.

## Out of scope (v2)

- Token revocation list (would require stateful storage)
- HMAC request signing (per-request nonce + timestamp) — see "Accepted v1 risks" above. Revisit if a higher-trust use case appears.
- Per-tenant rate limit tuning UI (one-size fits the SaaS for v1)
- Audit log persistence in DB (logging to channel for now)
- `widget_audit_log` table — pending real anomaly use cases
- WebSocket support — same threat model would apply; design extends naturally
- SRI hashes / CSP guidance — separate PR (threat D)
- Encryption-at-rest for messages — separate PR (threat C)

---

## File map

**New:**
- `app/Services/Widget/SessionTokenService.php`
- `app/Exceptions/Widget/InvalidSessionTokenException.php`
- `app/Http/Middleware/RequireWidgetSessionToken.php`
- `app/Http/Middleware/ThrottleWidgetPerIp.php`
- `tests/Feature/Api/V1/Widget/SessionTokenTest.php`
- `tests/Feature/Api/V1/Widget/PerIpThrottleTest.php`
- `config/widget.php` (toggles + thresholds)

**Modified:**
- `routes/api.php` — apply new middleware on the widget group
- `app/Http/Controllers/Api/V1/Widget/ChatController.php::init()` — emit `session_token`, `expires_at`
- `public/widget/chatbot.js` — store + send Bearer token, 401-retry interceptor
- `tests/Feature/Api/V1/Widget/*` existing tests — pass Bearer token where applicable

**Config / env:**
- `WIDGET_SESSION_DUAL_ACCEPT=true` (env, default true on deploy)
- `WIDGET_SESSION_TTL=1800` (seconds, default 30 min)
- `WIDGET_IP_INIT_PER_MIN=10`
- `WIDGET_IP_MESSAGE_PER_MIN=30`
- `WIDGET_IP_DAILY_CAP=5000` (raised from earlier draft of 1000 — see decision row)
