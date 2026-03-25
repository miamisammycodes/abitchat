# Foundation First: Package Updates + System Improvements

**Date:** 2026-03-25
**Status:** Approved
**Scope:** Aggressive package updates + code quality, caching, error handling, widget resilience, query optimization

---

## 1. Package Updates

### Composer (Major Bumps)

| Package | From | To | Notes |
|---------|------|----|-------|
| `laravel/framework` | 12.x | 13.x | Follow upgrade guide, update config files. Verify PHP version requirement (may need PHP 8.3+) |
| `laravel/tinker` | 2.x | 3.x | Minimal breaking changes |
| `phpunit/phpunit` | 11.x | 13.x | Two-major-version jump (11→12→13). Update test config, method signatures, check for deprecated assertions |
| `barryvdh/laravel-debugbar` | 3.x | 4.x | Dev-only, low risk |
| `prism-php/prism` | 0.98.5 | latest | Pre-1.0; audit ChatService for API changes |

All other Composer packages updated to latest (cashier, inertia, larastan, pint, sail, pail, collision, ziggy, dompdf, pdfparser, multitenancy).

### NPM (Major Bumps)

| Package | From | To | Notes |
|---------|------|----|-------|
| `vite` | 7.x | 8.x | Update vite.config.js |
| `laravel-vite-plugin` | 2.x | 3.x | Config changes for Vite 8 |
| `lucide-vue-next` | 0.555 | 1.0 | Icon imports may change |

All other npm packages updated to latest (vue, inertia, tailwind, postcss, axios, etc.).

### Strategy

Update Composer packages first (backend must work), then npm packages, then fix any build/runtime breaks before moving on to system improvements.

---

## 2. Code Quality — PHPStan & Authorization Policies

### PHPStan

- Bump from level 4 to **level 6**
- Fix the 1 existing error: nullsafe on non-nullable in `EnterpriseInquiryController` (line 28)
- Set `reportUnmatchedIgnoredErrors: true` so dead ignore patterns surface naturally after the level bump
- Clean up overly broad `ignoreErrors` — keep only patterns that are genuinely unfixable (e.g., Eloquent relationship type inference), remove the rest and fix the underlying code
- Fix all new errors surfaced by the level bump

### Authorization Policies

Add Laravel policies for 4 core tenant-scoped resources:

| Policy | Model | Key Rules |
|--------|-------|-----------|
| `ConversationPolicy` | Conversation | User can only access conversations belonging to their tenant |
| `LeadPolicy` | Lead | User can only view/export leads belonging to their tenant |
| `KnowledgeItemPolicy` | KnowledgeItem | User can only CRUD knowledge items in their tenant |
| `TransactionPolicy` | Transaction | User can only view their tenant's transactions |

**Implementation:**
- Each policy checks `$model->tenant_id === $user->tenant_id`
- Registered via auto-discovery or `AuthServiceProvider`
- Controllers call `$this->authorize('view', $model)` instead of relying solely on middleware
- Second layer of defense — prevents cross-tenant access even if middleware is bypassed

**Not adding:** Admin-side policies (admin is single-role superuser — middleware is sufficient).

**Note:** Widget API endpoints have no authenticated user, so policies do not apply there. Widget API authorization remains via manual API key → tenant lookup in `ChatController`.

---

## 3. Caching Layer

Currently **zero** `Cache::` calls exist in the codebase. Every request hits the database directly.

### Tenant & Plan Lookups (highest impact)

- Cache tenant by API key for widget requests: `Cache::remember("tenant:api_key:{$key}", 300, ...)` (5 min TTL)
- Cache tenant with plan for dashboard requests: `Cache::remember("tenant:{$id}:with_plan", 300, ...)`
- Invalidate on tenant update or plan change

### Usage Limit Checks

- `CheckUsageLimits` middleware is registered but **not currently applied to any routes**. As a prerequisite, wire it up to widget API routes (and adapt it to work with API-key-based tenant lookup instead of `$request->user()`)
- Cache current usage counters per tenant: `Cache::remember("tenant:{$id}:usage", 60, ...)` (1 min TTL)
- Invalidate when a new conversation/lead/usage record is created

### Knowledge Retrieval

- Cache chunk retrieval results by query hash + tenant: `Cache::remember("knowledge:{$tenantId}:" . md5($query), 600, ...)` (10 min TTL)
- Invalidate when knowledge items are created/updated/deleted for that tenant

### Cache Store

Use whatever `CACHE_STORE` env is set to (database by default, Redis in production). Code is store-agnostic.

### Invalidation

Call `Cache::forget()` in the relevant controller/service methods. No abstraction layer needed — invalidation points are few and well-defined.

---

## 4. Error Handling & LLM Resilience

### ChatService Retry Logic

- Add retry with exponential backoff: 3 attempts, delays of 1s → 2s → 4s
- Use Laravel's built-in `retry()` helper
- Only retry on transient failures (network errors, 429 rate limits, 500/503 from provider)
- Don't retry on 400/401/422 (bad request, auth, validation)
- **Retry applies to `generateResponse()` only.** `streamResponse()` cannot be retried once chunks have been sent to the client — it keeps its existing single-attempt + fallback pattern
- Keep existing fallback response as final safety net after all retries exhausted
- Log each retry attempt with attempt number

### Widget API Error Responses

- Standardize error response format across all ChatController endpoints:
  ```json
  {"error": "message", "code": "TENANT_NOT_FOUND"}
  ```
- Add proper HTTP status codes consistently (some endpoints currently return 200 with error messages)
- Add try-catch to `sendMessage()` and `startConversation()` which currently have none
- Keep the existing 60-second Prism timeout (already configured in ChatService) — this is appropriate for slower providers like Ollama in development

### Widget-Side Error Handling (chatbot.js)

- Add timeout to `apiCall()` using `AbortController` — **45 second timeout** for regular messages, **90 second timeout** for streaming (must exceed server-side 60s Prism timeout to avoid premature client-side aborts)
- Surface user-friendly error messages based on error codes

---

## 5. Widget Resilience

### Network Retry

- Add automatic retry in `apiCall()`: 2 retries with 1s → 3s delays
- Only retry on network errors and 5xx responses, not 4xx
- Show subtle "Reconnecting..." indicator during retries

### Offline Detection

- Listen to `navigator.onLine` + `online`/`offline` events
- When offline: disable input, show "You're offline" banner
- When back online: re-enable input, hide banner
- No auto-retry of lost messages

### Message Queue Safety

- Disable send button while message is in-flight (prevent double-sends)
- If send fails after retries, keep user's message in input field

### Request Timeouts

- 45s timeout for regular messages via `AbortController` (exceeds server-side 60s Prism timeout with margin)
- 90s timeout for streaming responses (streams can legitimately take longer)

### Not Adding

Service workers, localStorage message queuing, or reconnection of streaming responses mid-stream. These add significant complexity for marginal benefit at this stage.

---

## 6. Query Optimization

### Fix N+1 in KnowledgeBaseController

- **Current:** Loads all items, then calls `$item->chunks()->count()` per item in `map()` — 1 query per item
- **Fix:** Use `->withCount('chunks')` on the query, access as `$item->chunks_count` — single query with subselect

### Add Missing Database Index

- Add index on `usage_records(type, recorded_date)` — admin dashboard runs `UsageRecord::where('type', 'tokens')->sum('quantity')` which cannot use the existing `(tenant_id, type, recorded_date)` composite index since the admin query does not filter by `tenant_id`
- Simple migration, no data changes

### Scope Unbounded Queries

- Admin dashboard "total tokens" and "total revenue" queries scan entire tables
- Scope with date ranges to reduce scan size where appropriate
- Not a priority to cache since admin-only and infrequent

---

## Implementation Order

1. Package updates (Composer → npm → fix breaks)
2. Query optimization (smallest, lowest risk)
3. Caching layer (enables everything else to be faster)
4. Code quality (PHPStan + policies)
5. Error handling (ChatService + API responses)
6. Widget resilience (network retry, offline, timeouts)
