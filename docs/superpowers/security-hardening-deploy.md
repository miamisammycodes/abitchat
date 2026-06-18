# Security Hardening — Deploy & Ops Runbook

**Specs:** `docs/superpowers/specs/2026-06-16-security-hardening-cleanup-design.md`
**Plans:** `docs/superpowers/plans/2026-06-16-security-hardening.md` (PR 1),
`docs/superpowers/plans/2026-06-16-tech-debt-cleanup.md` (PR 2)

Consolidates the ops actions for the 2026-06-16 security + tech-debt batch that
do **not** ship automatically at merge. Nothing here changes merchant-facing
behavior on its own — each item is an explicit, gated ops step.

> Companion runbook: `docs/superpowers/crawler-ssrf-render-deploy.md` covers the
> crawler egress proxy + `CRAWLER_JS_RENDERING` flip. This document does not
> duplicate it; it references it where the egress proxy overlaps.

---

## 1. Widget monitors + alert thresholds

Two cache counters surface the health of the widget write-surface defenses.
Both are incremented in middleware and read by ops dashboards / alerts.

| Counter (cache key) | Incremented when | What it means | Alert |
|---|---|---|---|
| `widget_audit_failures` | A `WidgetAudit::log()` write throws and is swallowed | The audit trail is silently dropping events (DB/cache pressure, schema drift) | Page on **any** sustained nonzero rate (> 5 / 5 min) — audit gaps are a compliance risk |
| `widget_dual_accept_passthrough` | `RequireWidgetSessionToken` lets a token-less request through because `widget.session_dual_accept=true` | Legacy widgets are still hitting the write surface without a JWT | Track as a **burn-down to zero**. Alert if it stays nonzero approaching the planned strict-mode flip |

**Reading them (ad hoc):**
```bash
php artisan tinker --execute="echo cache('widget_audit_failures', 0).PHP_EOL; echo cache('widget_dual_accept_passthrough', 0).PHP_EOL;"
```

**Why the passthrough counter gates the dual-accept flip:** the strict-mode flip
(`WIDGET_SESSION_DUAL_ACCEPT=false`) is only safe once
`widget_dual_accept_passthrough` has been **flat at zero** for a full widget
cache/JWT TTL window — that proves every live widget is sending a JWT and no
real traffic will start 401'ing on the flip.

---

## 2. `TRUSTED_PROXIES` + dual-accept strict-mode flip

The widget JWT is IP-bound and the per-IP throttle keys on the client IP. Behind
a load balancer / CDN, the app sees the proxy IP unless `TRUSTED_PROXIES` is set
— so flipping dual-accept off **before** trusting proxies would collapse every
request's IP to the proxy, breaking IP-binding and rate-limits for all tenants.

**Order is mandatory:**

1. **Set `TRUSTED_PROXIES`** to the LB/CDN egress CIDR(s) (e.g.
   `10.0.0.0/8,172.16.0.0/12` for a private-network LB). Deploy. Confirm
   `request()->ip()` resolves to real client IPs (spot-check the widget audit
   log's `ip` field — it should show varied client IPs, not one proxy IP).

   > **`TRUSTED_PROXIES=*` does not work with this project.**
   > `bootstrap/app.php` splits the env value as a comma-separated string and
   > always passes an **array** to `TrustProxies::at()`. Laravel's
   > `setTrustedProxyIpAddresses()` only recognises the literal string `'*'`
   > (not an array containing `'*'`) as its trust-all wildcard — the array falls
   > through to `setTrustedProxyIpAddressesToSpecificIps()`, which tries to match
   > `'*'` as a literal CIDR and never trusts any proxy. **Use real CIDR(s)
   > instead.**
   >
   > If there is a single-hop proxy whose IP is not known statically, set
   > `TRUSTED_PROXIES=REMOTE_ADDR`. That special string is resolved to the
   > actual connecting IP at request time inside
   > `setTrustedProxyIpAddressesToSpecificIps()` — the one bootstrap-supported
   > path that does not require knowing the CIDR in advance. Do **not** use
   > `REMOTE_ADDR` behind multi-hop topologies (CDN → LB → app) because it
   > would only trust the last hop (the LB), not the CDN.
2. **Confirm the burn-down:** `widget_dual_accept_passthrough` flat at zero for ≥
   one JWT TTL window (see §1).
3. **Flip:** set `WIDGET_SESSION_DUAL_ACCEPT=false`. Token-less
   `POST /api/v1/widget/message` now returns `401 session_token_required`.
4. **Restart the queue workers** after the env change — a long-running
   `queue:work` caches `.env` and will keep the old value otherwise.

`.env.example` already ships `WIDGET_SESSION_DUAL_ACCEPT=false` (PR 1) with the
TrustProxies caveat inline; the live default in prod stays `true` until this
sequence completes.

---

## 3. Egress proxy as a supervised process

When `CRAWLER_JS_RENDERING` is enabled, the Node validate-and-pin egress proxy
(`resources/node/egress-proxy.mjs`) must run as a supervised localhost process.
Full rationale and the env interlock live in
`docs/superpowers/crawler-ssrf-render-deploy.md` §2; this is the in-repo process
unit that doc said was missing.

### systemd unit (`/etc/systemd/system/abitchat-egress-proxy.service`)

```ini
[Unit]
Description=AbitChat crawler egress validate-and-pin proxy
After=network.target

[Service]
Type=simple
User=abitchat
WorkingDirectory=/var/www/abitchat
ExecStart=/usr/bin/node resources/node/egress-proxy.mjs 8118
Restart=always
RestartSec=2
# Bound to localhost inside the app; do not expose externally.
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now abitchat-egress-proxy
systemctl status abitchat-egress-proxy
```

### supervisor alternative (`/etc/supervisor/conf.d/abitchat-egress-proxy.conf`)

```ini
[program:abitchat-egress-proxy]
command=/usr/bin/node resources/node/egress-proxy.mjs 8118
directory=/var/www/abitchat
user=abitchat
autostart=true
autorestart=true
startsecs=2
stdout_logfile=/var/log/abitchat/egress-proxy.out.log
stderr_logfile=/var/log/abitchat/egress-proxy.err.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start abitchat-egress-proxy
```

Then set `CRAWLER_EGRESS_PROXY=127.0.0.1:8118` and only after that
`CRAWLER_JS_RENDERING=true` (the `PageRenderer::enabled()` interlock keeps render
off until the proxy is configured).

---

## 4. DK Bank config decisions to confirm with DK

PR 1 made the DK integration tolerant/configurable but kept it dark behind
`DK_BANK_ENABLED=false`. Before any production flip, confirm these with DK and
set the env accordingly:

| Config | Default (PR 1) | Confirm with DK |
|---|---|---|
| `services.dk_bank.mcc_code` | `5817` | DK may require `5734`. Set `DK_BANK_MCC_CODE` to the value DK assigns. |
| `services.dk_bank.account_match` | `exact` | If DK returns a masked/reformatted `credit_account`, switch to `suffix`. |
| `services.dk_bank.account_match_digits` | `4` | Last-N digits compared in `suffix` mode. |
| RRN format | regex `^[A-Za-z0-9\/\- ]{4,32}$` (32 = `dk_rrn` column width) | Confirm real cross-bank RRNs (hyphens/slashes) pass; tighten only if DK specifies an exact format. |
| `extractPaidStatusData` envelope | object `response_data.status` with array `response_data[0].status` fallback | Confirm which shape DK production returns; both are parsed. |

DK end-to-end production verification stays out of scope until DK answers the
open settlement/masking questions (per spec Out-of-Scope). The killswitch keeps
the feature invisible (`EnsureDkBankEnabled` → 404) until then.

---

## 5. Carried-over pending deploys (not new in this batch)

These predate this batch and remain pending — listed here so the deploy owner
has one place to check:

- **Free-plan lifecycle (PR #40):** `php artisan migrate --force` (+ backfill),
  ensure the scheduler runs the daily lifecycle command, queue + Resend
  configured.
- **Post-scraping re-crawl (PR #41/#42):** re-crawl existing tenants after the
  clean-extraction + render changes deploy, so thin/SPA pages re-index.
