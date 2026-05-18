# DK Bank QR Payment — Design

**Date:** 2026-05-15
**Status:** Brainstorm locked + advisor-reviewed; ready for implementation plan.
**Sources:**
- `/Users/sam/Dev/laravel/abitpay/docs/zala.bt Payment Gateway-doc.docx (1) (1).pdf` (29-page DK Bank API spec — confidential)
- `/Users/sam/Dev/laravel/abitpay/docs/dk-bank-qr-api-guide.md` (standalone QR integration guide)
- DK Bank email reply (2026-05-15) clarifying that `/v1/intra-transaction/status` accepts cross-bank RRN, contradicting page 27 of the spec

**Advisor review changes (2026-05-15):**
- Strengthened Task 0 with a **blocking** real-cross-bank verification probe (#4). Non-DK design depends entirely on DK's email contradicting their own doc; not committing to RRN-path code until empirically verified.
- Added unique constraint on `dk_rrn` + a recency guard (`txn_ts >= transaction.created_at`) to close the cross-tenant RRN replay exploit.
- Fixed `transaction_date` source: two-candidate-date polling (`created_at` day + next day) instead of single day; handles midnight-rollover edge case.
- Flagged `Transaction::approveAndActivate()` extraction as ~30 lines + 4 typed exceptions, not a one-liner — read the admin approve action to confirm.
- Custom `dk-rrn-verify` rate limiter keyed per Transaction + per Tenant, not per-IP (NAT-shared offices would otherwise share rate-limit budget).
- `request_id` length gotcha: UUID v4 with dashes is 36 chars but DK requires 10-32; strip dashes for a 32-char hex.
- Amount comparison cast explicitly to float on both sides (DK returns string `"150.00"`, our column is Decimal cast).

## Goal

Replace the current "merchant pays offline → types 5 fields → admin approves" subscription flow's DK Bank path with a QR-based flow that auto-verifies via DK's status API:

- **DK→DK payers**: zero-touch. We poll DK's status endpoint with our reference number; transaction approves itself within seconds of payment.
- **Non-DK payers**: scan QR with any Bhutanese bank app (RMA common-standard), pay, then paste the bank-generated RRN into our page. We call the same DK status endpoint with the RRN. Approves on success.
- Admin manual approval workflow stays alive for the existing five non-DK bank options on the manual form (no breaking change). Admins are no longer in the DK QR path.

## Scope decisions (locked via brainstorm)

| Decision | Choice | Rationale |
|---|---|---|
| Target project | `chatbot` (current cwd) | Adds DK QR alongside the existing manual `dk` option in the bank dropdown. Not bringing in abitpay. |
| Trigger | Merchant self-serve on `/dashboard/billing/subscribe/{plan}` | Existing entry point. |
| v1 scope | QR generation + auto-verify via §10 (`/v1/intra-transaction/status`) | DK confirmed §10 works cross-bank when given the RRN. |
| Other DK endpoints | **Out** — no pull-payment (§3/§4), no §8/§9 (need `transaction_id` we never have) | Reduces surface area. |
| Verification path | DK→DK: backend polls §10 with our `dk_reference_no`. Non-DK: user pastes RRN; we call §10 with the RRN. | Same endpoint, two key sources. |
| UI placement | Side-by-side: QR card (recommended) + existing manual form, on the same Subscribe page | Most discoverable; preserves the non-DK manual fallback unchanged. |
| Polling cadence | Frontend `fetch` every 3 seconds, 2-minute window. No backend job. | DK→DK credits in seconds; aggressive polling matches reality. User closes tab → polling stops, no zombie state. |
| Failure UX | Inline error in QR card + "Use manual form" link visible | Never block a merchant from subscribing because DK is down. |
| QR style | Dynamic (amount baked into QR, payer can't change it) | Plan price is known; locking the amount removes a class of bug. |
| Killswitch | `DK_BANK_ENABLED=false` env flag hides the QR card entirely | Deployable dark; lets us flip off if DK breaks in production. |
| Bundled refactor | Extract `Transaction::approveAndActivate()` from the existing admin approve action so the auto-verify path can reuse it | Two callers (admin + auto), one canonical code path. |

## Brainstorm trail — key decisions and the reasoning behind them

**Q1: What does the merchant flow look like end-to-end?** → "QR + auto-verification via DK status API" (rejected later as too narrow; folded into the two-path design below after the RRN clarification).

**Q2: Do we have DK's status-check API doc?** → Yes — DK shared the 29-page PDF. §10 (`/v1/intra-transaction/status`) is the only status endpoint that takes a key we control (`reference_no`).

**Q3: How to handle non-DK payers?** → Initial answer was "manual transaction-number entry + admin approval." Revised after DK clarified §10 accepts cross-bank RRN.

**Q4: UI placement?** → Side-by-side QR + manual form on the Subscribe page.

**Q5: Polling strategy?** → Frontend polls every 3s for 2 min. No backend job. Simpler state machine.

**Q6: Failure UX?** → Inline error + manual form fallback link.

**Q7 (sticky moment): Can we get fully-automatic cross-bank auto-verification?** → No, not with currently-documented APIs. DK doesn't expose a webhook or a "recent credits to this account" API. Pushed back on user, surfaced primary-source quotes from the doc, then sent a follow-up to DK.

**Q8 (after DK reply): What about the RRN approach?** → DK said cross-bank verification works via `/v1/intra-transaction/status` if we obtain the RRN from the payer's bank. RRN is a 12-ish-digit reference DK Bank, BoB, BNB, etc. all generate per transaction (commonly labeled "Journal No", "RRN", or "Transaction Reference"). The payer sees it on their bank's success screen after authorizing the payment. Manual paste by the user is the only way to relay it to us — no automatic channel.

**Q9: Same endpoint or different endpoint for the RRN path?** → DK confirmed it's the same `/v1/intra-transaction/status`. We just pass the RRN as the `reference_no` field instead of our own.

**Future-state questions deliberately not blocking v1:**
- Statement / recent-credits API from DK — would unblock full auto-verification cross-bank
- Webhook from DK — same
- Both are TODOs to ask DK in 3-6 months; not on v1 critical path

## Architecture

```
                         ┌──────────────────────────────────────┐
   Subscribe.vue         │  Subscribe page (resources/js/Pages/ │
   (existing, modified)  │  Client/Billing/Subscribe.vue)       │
                         └──────────────┬───────────────────────┘
                                        │
                  ┌─────────────────────┴────────────────────────┐
                  ▼                                              ▼
        ┌─────────────────────────┐                  ┌──────────────────────────┐
        │  Pay with DK QR (new)   │                  │  Manual form (unchanged) │
        │  ─────────────────────  │                  │  Used for non-DK + DK    │
        │  Shows QR + status      │                  │  fallback. Admin still   │
        │  Live poll DK→DK        │                  │  approves these.         │
        │  RRN paste for non-DK   │                  │                          │
        └─────────────┬───────────┘                  └──────────────────────────┘
                      │
                      ▼
        POST /dashboard/billing/dk-qr/{plan}                    [Controller]
        DkBankQrController::start()
                      │
                      ▼
        DkBankQrService::startQrSession(Tenant, Plan)           [Service]
          1. Create Transaction(status=awaiting_payment,
             payment_method=dk_qr, dk_reference_no=...)
          2. Call DkBankClient::postSigned('/v1/generate_qr', ...)
          3. Return DkQrSession DTO { transaction, qrImageBase64 }
                      │
                      ▼
        DkBankClient (HTTP / token cache / RSA signing)         [Service]
          - postSigned()    : adds DK-Signature + token + headers
          - postUnsigned()  : only for /v1/auth/token
          - postPlain()     : only for /v1/sign/key (returns PEM string)
          - accessToken()   : Redis-cached 1500s
          - privateKey()    : disk-cached forever (storage/app/dk_pg.pem, 0600)
                      │
                      ▼
                DK Bank API (UAT or production)

Polling path (DK→DK):
   GET /dashboard/billing/dk-qr/{transaction}/status (JSON)
     → DkBankQrService::checkDkIntraStatus(Transaction)
     → DkBankClient::postSigned('/v1/intra-transaction/status', { reference_no: $tx->dk_reference_no, ... })
     → if response_code='0000' AND response_data[0].status='0':
           Transaction::approveAndActivate() (DB transaction, locked row)
     → returns JSON { state: 'paid' | 'pending' }
   Frontend polls every 3s, redirects on 'paid'.

RRN path (non-DK):
   POST /dashboard/billing/dk-qr/{transaction}/verify-rrn { rrn }
     → throttled to 5 attempts / hour per Transaction
     → DkBankQrService::verifyByRrn(Transaction, $rrn)
     → DkBankClient::postSigned('/v1/intra-transaction/status', { reference_no: $rrn, ... })
     → on success: amount + credit_account match check, then approveAndActivate()
     → returns JSON { state, message }
```

## Data model

**Migration: `2026_05_15_000001_add_dk_qr_fields_to_transactions_table.php`**

```php
Schema::table('transactions', function (Blueprint $table) {
    $table->string('dk_reference_no', 32)->nullable()->unique()->after('reference_number');
    $table->string('dk_rrn', 32)->nullable()->unique()->after('dk_reference_no');
    $table->timestamp('dk_status_last_checked_at')->nullable()->after('admin_notes');
});
```

**Why both `dk_reference_no` and `dk_rrn` are unique**: prevents the cross-tenant RRN replay attack. If Tenant A pastes Tenant B's real RRN (same beneficiary account, same plan price would otherwise match), the unique constraint on `dk_rrn` makes the second `verifyByRrn` attempt fail at DB-write time. Combined with the recency check below, this closes the free-upgrade exploit.

**Transaction status values** (additive — existing values unchanged):
- `pending` (existing)
- `approved` (existing)
- `rejected` (existing)
- **`awaiting_payment` (NEW)** — Transaction row created when merchant clicks "Pay with DK QR" before they've paid. Moves to `approved` on auto-verify or to `rejected` on 24h cleanup.

**Transaction payment_method values** (additive):
- existing: `bob, bnb, dpnb, bdbl, tbank, dk`
- **`dk_qr` (NEW)** — distinguishes DK QR rows from rows created via the manual form's "dk" option.

**`dk_reference_no` format**: `DKQR-{transaction_id}-{6char random base32}` — e.g. `DKQR-742-A3F9K2`. Unique constraint; never mutated after creation.

**`dk_rrn`**: nullable. Set by `verifyByRrn` on successful match. NULL for DK→DK rows (where we matched by `dk_reference_no` instead). Used for support lookups + audit.

**State machine**

```
                ┌─────────────────────┐
                │ awaiting_payment    │
                └──────────┬──────────┘
                           │
       ┌───────────────────┼───────────────────┐
       │                   │                   │
   DK→DK poll          RRN verify          24h cleanup
   succeeds            succeeds            (scheduled cmd)
       │                   │                   │
       ▼                   ▼                   ▼
   ┌──────────┐        ┌──────────┐        ┌──────────┐
   │ approved │        │ approved │        │ rejected │
   └──────────┘        └──────────┘        └──────────┘
```

Existing manual-form flow stays untouched: it creates Transaction directly at `status=pending`, admin approves to `approved`. No interference.

## Service layer

### `App\Services\Payment\DkBank\DkBankClient`

**Responsibility**: low-level HTTP + auth + signing. Knows nothing about plans, transactions, or QR codes.

```php
final class DkBankClient
{
    public function postSigned(string $endpoint, array $body): array;
    public function postUnsigned(string $endpoint, array $body): array;   // only /v1/auth/token
    public function postPlain(string $endpoint, array $body): string;     // only /v1/sign/key (returns PEM)

    private function accessToken(): string;
    private function privateKey(): string;
    private function signBody(array $body, string $timestamp, string $nonce): string;
    private function canonicalJson(array $body): string;
    private function refreshTokenAndRetry(callable $request): array;      // for 5001 retry-once
}
```

**`request_id` generation (spec gotcha)**: DK's spec requires `request_id` length 10-32 (page 4). PHP's `Str::uuid()->toString()` returns 36 chars (with dashes). Use `str_replace('-', '', (string) Str::uuid())` to get a 32-char hex string. Centralize this in `DkBankClient::generateRequestId()` so it's never wrong twice.

**Canonical JSON rule (critical):**

```php
private function canonicalJson(array $body): string
{
    return json_encode(
        $this->sortKeysRecursive($body),
        JSON_UNESCAPED_SLASHES,   // matches Python's default
        // NO JSON_UNESCAPED_UNICODE — must escape non-ASCII to \uXXXX
        // because DK's server uses Python's json.dumps(ensure_ascii=True)
    );
}
```

The signature is a JWT (RS256) wrapping `{ data: base64(canonical), timestamp: ISO8601-UTC, nonce: uuid4 }`. Header: `DK-Signature: DKSignature {jwt}` (literal prefix with space).

**Token caching**: `Cache::remember('dk_bank:access_token', 1500, fn() => $this->fetchToken())`. 5-minute headroom below DK's 1800s expiry.

**Private key**: fetched once via `/v1/sign/key` (returns PEM as plain text), persisted to `storage/app/dk_pg.pem` with mode `0600`. Re-fetched only if a signature is rejected with the "key revoked" error.

**`5001` policy**: on auth error, invalidate token cache, refresh once, retry once. No infinite loop.

### `App\Services\Payment\DkBank\DkBankQrService`

**Responsibility**: domain layer. Knows Tenants, Plans, Transactions.

```php
final class DkBankQrService
{
    public function startQrSession(Tenant $tenant, Plan $plan): DkQrSession;
    public function checkDkIntraStatus(Transaction $transaction): DkStatusResult;
    public function verifyByRrn(Transaction $transaction, string $rrn): DkStatusResult;
}

readonly class DkQrSession
{
    public function __construct(
        public Transaction $transaction,
        public string $qrImageBase64,
    ) {}
}

readonly class DkStatusResult
{
    public function __construct(
        public DkStatusState $state,        // paid | pending | failed
        public ?string $matchedReferenceNo, // RRN if RRN path, dk_reference_no if intra path
        public ?Carbon $paidAt,
        public ?string $errorMessage,
    ) {}
}
```

**`startQrSession`** flow:
1. Open DB transaction. Create Transaction row (status=`awaiting_payment`, payment_method=`dk_qr`, dk_reference_no=`DKQR-...`, amount=`$plan->price`, reference_number=NULL).
2. Call `DkBankClient::postSigned('/v1/generate_qr', [...])` with body `{request_id, currency: 'BTN', bene_account_number, amount, mcc_code, remarks: "Plan: {$plan->name}", reference_no: dk_reference_no}`.
3. On non-`0000` response → throw `DkQrGenerationException` (DB transaction rolls back, no orphan Transaction row).
4. Return DTO with base64 image. Commit.

**`checkDkIntraStatus`** flow:
1. Update `dk_status_last_checked_at = now()`.
2. **Try the call with two candidate dates** — `$transaction->created_at->toDateString()` first; on `3001` retry with `$transaction->created_at->addDay()->toDateString()`. DK's `transaction_date` parameter means "the date the customer initiated the payment," not "the date we generated the QR" — a merchant who opens the QR at 23:55 and pays at 00:02 would otherwise fail the same-day query. After 2 days, the next poll moves on as normal `pending`.
3. Request body: `{request_id, reference_no: $transaction->dk_reference_no, transaction_date: <candidate>, bene_account_number: config('services.dk_bank.beneficiary_account')}`.
4. On `response_code='0000'` AND `response_data[0].status='0'`:
   - Verify `(float) $response['response_data'][0]['amount'] === (float) $transaction->amount` (DK returns amount as a string like `"150.00"` per the doc; cast both sides explicitly — strict `==` between string and Decimal-cast will surprise)
   - Verify `response_data[0].credit_account === config('services.dk_bank.beneficiary_account')` → mismatch = fail
   - Wrap in DB transaction, lock Transaction row, call `Transaction::approveAndActivate()`.
   - Return `state: 'paid'`.
5. On `response_code='3001'` after both candidate-date attempts → return `state: 'pending'` (normal "not paid yet").
6. Other errors → log + return `state: 'pending'` (transient failure; don't fail the polling loop).

**`verifyByRrn`** flow: identical to `checkDkIntraStatus` except:
- `reference_no = strtoupper(trim($rrn))` (instead of `$transaction->dk_reference_no`)
- **Recency guard**: parse `response_data[0].txn_ts` as Carbon; reject if it's earlier than `$transaction->created_at` (the QR was generated AFTER the alleged payment — impossible for a legitimate payment, certain proof of RRN replay)
- On success, set `$transaction->dk_rrn = $rrn` inside the DB transaction. The unique constraint on `dk_rrn` is what catches the race-condition variant of replay; the recency guard catches the slow variant.
- On `3001`, return `state: 'failed'` with user-friendly message "Reference number not found — double-check from your bank's receipt"
- On unique-constraint violation when writing `dk_rrn`, return `state: 'failed'` with message "This reference number has already been used — contact support if this is unexpected" and log a `[security] dk_rrn replay attempt` warning with both transaction_ids.

### `Transaction::approveAndActivate()` (refactored from admin approve action)

The existing `Admin\TransactionController::approve()` is ~25 lines inside a `DB::transaction(...)` block: it locks the Transaction row (`lockForUpdate`), guards `status === 'pending'`, asserts tenant + plan exist, asserts `plan->is_active`, updates status/admin_notes/approved_by/approved_at, then calls `$tenant->extendPlan($plan)`. Multiple `RuntimeException` short-codes (`ALREADY_PROCESSED`, `PLAN_INACTIVE`, `RECORD_MISSING`) surface as user-friendly flash errors.

The two callers diverge on:
- **Starting status guard** — admin approves from `pending`; auto-verify approves from `awaiting_payment`. Method signature takes an allowed-from list: `approveAndActivate(array $allowedFromStatuses, ?int $adminId = null, ?string $adminNotes = null)`.
- **Error handling** — admin path catches and shows flash messages; auto-verify path lets the exception propagate (controller returns JSON error). The method itself just throws cleanly typed exceptions.
- **`reference_number` requirement** — admin's manual-form path requires a 6-char reference_number; auto-verify path has none. Acceptable since the column is already nullable for `awaiting_payment` rows.

Concretely, extraction is ~30 lines of method body + 4 typed exception classes (`TransactionAlreadyProcessed`, `TransactionPlanInactive`, `TransactionRecordMissing`, `TransactionStatusNotAllowed`). Admin controller becomes a 10-line catch block translating exceptions to flash messages. This is **not a one-line trivial refactor** — flagged as a real task (Task 4 in the PR shape) with its own test suite for both call paths.

## Controllers + routes

**New routes** (in `routes/web.php`, inside the `client.billing` group, requires `auth` + tenant middleware):

```php
Route::post('/billing/dk-qr/{plan}', [DkBankQrController::class, 'start'])
    ->name('client.billing.dk-qr.start');

Route::get('/billing/dk-qr/{transaction}/status', [DkBankQrController::class, 'status'])
    ->name('client.billing.dk-qr.status')
    ->middleware('throttle:60,1');  // 60/min per session — polling is aggressive

Route::post('/billing/dk-qr/{transaction}/verify-rrn', [DkBankQrController::class, 'verifyRrn'])
    ->name('client.billing.dk-qr.verify-rrn')
    ->middleware('throttle:dk-rrn-verify');  // custom limiter — keyed per Transaction, NOT per IP
```

**`dk-rrn-verify` rate limiter** (define in `AppServiceProvider::boot()` or a `RateLimiterServiceProvider`):

```php
RateLimiter::for('dk-rrn-verify', function (Request $request) {
    $transactionId = $request->route('transaction')?->id ?? 'unknown';
    return [
        Limit::perHour(5)->by("dk-rrn:tx:{$transactionId}"),
        Limit::perHour(20)->by("dk-rrn:tenant:{$request->user()?->tenant_id}"),
    ];
});
```

Per-transaction limit catches typos on a single QR session; per-tenant limit catches attack attempts across multiple sessions. **Not IP-based** — merchants behind NAT (most Bhutanese offices share an IP) would otherwise lock each other out.

**`DkBankQrController` methods:**
- `start(Plan)` — calls `DkBankQrService::startQrSession()`, returns Inertia render of a new `Client/Billing/DkQrSession.vue` page with the QR + status polling. On `DkQrGenerationException`, redirect back to Subscribe with a flash error and a `?dk_failed=1` query param so the manual form is auto-focused.
- `status(Transaction)` — authorizes ownership, calls `checkDkIntraStatus`, returns JSON.
- `verifyRrn(Transaction, FormRequest)` — authorizes ownership, validates `{rrn: required|alpha_num|min:4|max:32}`, calls `verifyByRrn`, returns JSON. On success, frontend redirects to billing.

**Authorization**: existing `Transaction` policy. Add `view` and `update` rules ensuring `$transaction->tenant_id === $user->tenant_id` and `$transaction->status === 'awaiting_payment'` for the status + RRN endpoints.

## Frontend changes

### Subscribe.vue (modified)

Add a left column with the "Pay with DK QR" card. Existing manual form moves to the right (or remains where it is, with a new sibling card on the left). DK card hidden entirely if `config('services.dk_bank.enabled') === false` — passed via Inertia shared props.

```vue
<!-- Pseudocode shape; not literal -->
<div v-if="$page.props.dkBankEnabled" class="dk-qr-card">
  <h3>Pay with DK Bank QR (instant)</h3>
  <p>Scan with any Bhutanese bank app. Instant verification for DK Bank users.</p>
  <Link :href="route('client.billing.dk-qr.start', plan.id)" method="post" as="button">
    Generate QR
  </Link>
</div>
```

### Client/Billing/DkQrSession.vue (new)

The QR + status polling + RRN paste page. Props: `{ transaction, qrImageBase64, plan }`.

State machine in the component:
- `pollingForDkIntra` (default after mount) — show QR + "Waiting for payment..." spinner. Background `setInterval(3000)` calls the status endpoint. Stop after 120s with a "We're not seeing your payment yet" notice; show RRN box more prominently.
- `verifyingByRrn` — user pasted RRN + clicked Verify; show inline spinner on the verify button.
- `paid` — server confirmed; show success ✓ and redirect to billing in 1s.
- `failed_rrn` — server rejected the RRN; show inline error under the input.
- `failed_generation` — only relevant on initial page render if controller hit DkQrGenerationException and redirected back; renders an Alert with link to manual form.

RRN input is visible from the start with hint text: *"Already paid from a non-DK bank? Paste your bank's reference number — labeled Journal No, RRN, Transaction ID, or Reference No on your bank's receipt. Length varies by bank."*

## Failure handling

| Failure | Response |
|---|---|
| QR generation API returns non-`0000` | Throw `DkQrGenerationException` → controller redirects back to Subscribe with flash error and `?dk_failed=1`. No orphan Transaction. |
| QR generation network timeout | Same as above. Configure Guzzle timeout=30s. Log full DK response body. |
| Status polling `response_code=3001` | Normal "not paid yet" — frontend keeps polling. |
| Status polling network failure | Frontend ignores one failure; logs and retries on next tick. Three consecutive failures = stop polling, show "Couldn't check status — refresh to retry." |
| Status polling: `0000` + status=`0` but amount mismatch | Reject. Log full payload. Show "Payment amount didn't match — contact support with reference DKQR-742-A3F9K2." |
| Status polling: `0000` + status=`0` but credit_account mismatch | Same as above — different message. |
| RRN verify: DK returns `3001` | Inline error: "We couldn't find that reference number. Double-check from your bank's receipt. If you paid within the last few minutes, wait 30 seconds and try again." |
| RRN verify: rate limit hit (5/hr) | "Too many attempts. Contact support with reference DKQR-... and we'll verify manually." |
| `5001` auth error mid-flight | `DkBankClient` transparently refreshes token + retries once. No user-visible effect. |
| Service totally down | `DK_BANK_ENABLED=false` env flag hides the QR card entirely. Merchants see only the manual form. |
| Abandoned `awaiting_payment` rows older than 24h | Daily `php artisan dk:cleanup-abandoned-qr` scheduled command flips them to `rejected` with `admin_notes='auto-expired'`. |

## Config

`config/services.php` adds:

```php
'dk_bank' => [
    'enabled' => env('DK_BANK_ENABLED', false),
    'base_url' => env('DK_BANK_BASE_URL'),
    'api_key' => env('DK_BANK_API_KEY'),
    'username' => env('DK_BANK_USERNAME'),
    'password' => env('DK_BANK_PASSWORD'),
    'client_id' => env('DK_BANK_CLIENT_ID'),
    'client_secret' => env('DK_BANK_CLIENT_SECRET'),
    'source_app' => env('DK_BANK_SOURCE_APP'),
    'beneficiary_account' => env('DK_BANK_BENEFICIARY_ACCOUNT'),
    'mcc_code' => env('DK_BANK_MCC_CODE', '5817'),  // Digital Goods default
    'private_key_path' => storage_path('app/dk_pg.pem'),
],
```

`.env.example` gains the matching keys with empty values + a comment block referencing this design doc.

## Testing strategy

### Unit tests (no network, no DB)

- `DkBankClient::canonicalJson()` — fixtures with various key orders, nested arrays, ASCII-only, non-ASCII (which we explicitly escape). Assert byte-exact output. **This is the most important test — most signing bugs surface here.**
- `DkBankClient::signBody()` — assert JWT decodes back to `{data, timestamp, nonce}` with the canonical body's base64.
- `DkBankClient` token caching — second call within TTL doesn't refetch.
- `DkBankClient` 5001 retry — first call returns 5001, second call returns 0000, assert one retry.

### Feature tests (Pest, real DB, mocked `DkBankClient`)

- Subscribe page shows the QR card when `dk_bank.enabled=true` and hides it when false.
- `POST .../dk-qr/{plan}` creates Transaction with correct fields and renders the new Vue page with base64 image.
- `GET .../dk-qr/{transaction}/status` returns `paid` when mocked client returns 0000+status=0; returns `pending` on 3001.
- `POST .../dk-qr/{transaction}/verify-rrn` happy path: status=paid, dk_rrn set, plan activated.
- Authorization: another tenant's transaction returns 403.
- Amount mismatch: status check returns success with wrong amount → transaction stays awaiting, flash error shown.
- Rate limit: 6th RRN verify call within an hour returns 429.
- Daily cleanup command marks 24h+ awaiting_payment rows as rejected.
- `DkBankQrService::startQrSession` rolls back Transaction on DkBankClient throwing.

### Browser smoke (manual, pre-PR)

Run dev server with UAT creds, walk the four paths:
1. Subscribe → Pay with DK QR → simulate DK→DK success (mock or real UAT app) → auto-redirect to billing
2. Subscribe → Pay with DK QR → paste valid RRN → success
3. Subscribe → Pay with DK QR → paste invalid RRN → inline error
4. Subscribe → Pay with DK QR → close tab after 2 min → next day cleanup command flips to rejected

## Task 0 — verification probes (before any implementation tasks run)

Before Task 1 of the plan does anything, run these against UAT (or production sandbox) to verify reality matches our model. **The non-DK branch of this design is built entirely on DK's email reply that contradicts page 27 of their own doc. We do NOT commit to writing the RRN path code until probe #4 confirms it actually works against a real cross-bank payment.**

1. **Canonical JSON signing matches DK's server**: Sign a sample body with the rules above, POST to `/v1/auth/token` (which doesn't actually validate the body signature, but proves our token fetch works). Then POST to `/v1/sign/key` (which requires the token, also no signature validation). Then POST to `/v1/generate_qr` — this IS signature-validated. Confirm we get `response_code: '0000'` back. **If signature fails here, fix `canonicalJson()` before going further.**

2. **§10 status check with DK→DK `reference_no`**: Generate a UAT QR with a known `reference_no`. Scan with UAT DK mobile app + complete payment. Call `/v1/intra-transaction/status` with that `reference_no`. Confirm:
   - `response_code = '0000'`, `response_data[0].status = '0'`
   - `response_data[0].amount` matches the QR amount (note exact type — string vs number)
   - `response_data[0].credit_account` matches our beneficiary
   - `response_data[0].txn_ts` is present + ISO 8601 (used for recency guard)
   - Document the literal response shape — the `verifyByRrn` and `checkDkIntraStatus` parsers depend on it being identical to the non-DK case.

3. **§10 status check with arbitrary RRN**: Call `/v1/intra-transaction/status` with a fabricated RRN. Confirm `response_code: '3001'` cleanly. This proves the failure path our UI surfaces.

4. **🚨 BLOCKING: §10 status check with a REAL cross-bank RRN.** Initiate an actual cross-bank payment to our UAT beneficiary account from a non-DK Bhutanese bank app (BoB, BNB, or any available), grab the RRN from the receipt/SMS, then call `/v1/intra-transaction/status` with that RRN. Confirm we get `response_code = '0000'` + `status = '0'` exactly like the DK→DK case. **If this returns `3001`, the entire non-DK branch of this design is wrong. In that case: rip the RRN path out of the plan, ship DK→DK only, and the manual transaction-number form stays as the only non-DK path. No RRN code gets written until this probe succeeds.**

   If UAT is bank-isolated and we can't run this in UAT, run it in production with a tiny real payment (Nu. 1.00 from sam's personal non-DK account to our merchant account) BEFORE writing the RRN-path code. Don't ship blind.

5. **Token caching observed**: Make two `/v1/generate_qr` calls back-to-back; confirm Redis has the cached token and DK's token endpoint was hit only once.

6. **Response date sensitivity** for §10: take the successful response from probe #2, then call `/v1/intra-transaction/status` again with the SAME `reference_no` but `transaction_date` set to the next day. Document whether DK returns `0000` (date param doesn't strictly bind) or `3001` (date param is strict). This determines whether the two-candidate-date polling logic is necessary.

Findings update the implementation plan **before** any production-shape code is committed. Specifically: probe #4 outcome decides whether the plan has 11 tasks or 7.

## Out of scope

- Pull-payment flow (DK §3/§4 endpoints) — pull payments require remitter account number entry on our side + OTP; different UX, different design.
- DK status endpoints §8 (`/v1/transaction/status`) and §9 (`/v1/transactions/status`) — require `transaction_id` we never receive at QR generation.
- Refunds / chargebacks.
- Multi-currency — BTN only.
- Static QR (amount = 0, payer-set amount). Always dynamic.
- Webhook integration — none documented; would require DK to add it.
- Replacing or removing the existing manual transaction-number form for the 5 non-DK banks. That flow stays as today.
- Admin "force approve / reject DK QR" UI — admins use the existing Transaction approve/reject action; no DK-specific admin tooling.
- User-facing "cancel my QR session" button — abandoned sessions auto-cleanup via the daily command.
- Server-side polling job for stragglers (we explicitly chose frontend polling).
- Pre-filling the RRN field via clipboard or push API.
- Future statement-API / webhook integration if DK adds it later — separate v2 spec.

## PR shape (hint for the writing-plans phase)

Single PR, one feature branch `feat/dk-bank-qr-payment`. Tasks roughly:

| # | Task | Why |
|---|---|---|
| 0 | UAT verification probes (see above) | Validates assumptions before any production code |
| 1 | `DkBankClient` + signing + token cache + tests | Foundation; nothing else works without this |
| 2 | `config/services.php` block + `.env.example` + `DK_BANK_ENABLED` killswitch | Wire env config |
| 3 | Migration: `dk_reference_no`, `dk_rrn`, `dk_status_last_checked_at`, `awaiting_payment` status, `dk_qr` payment_method | DB shape |
| 4 | `Transaction::approveAndActivate()` extraction + tests + refactor admin approve to use it | Decouple before adding the second caller |
| 5 | `DkBankQrService::startQrSession` + tests | Domain orchestration |
| 6 | `DkBankQrController::start` + route + new `DkQrSession.vue` page (QR display only, no polling yet) | Visible thing on screen |
| 7 | `DkBankQrService::checkDkIntraStatus` + `status` endpoint + frontend polling | DK→DK happy path |
| 8 | `DkBankQrService::verifyByRrn` + `verify-rrn` endpoint + RRN paste UI + amount/credit_account guards | Non-DK path |
| 9 | Subscribe.vue side-by-side layout + `dkBankEnabled` shared prop + `DkQrGenerationException` redirect path | UX integration |
| 10 | Daily cleanup command + scheduler binding + tests | Hygiene |
| 11 | Browser smoke + two `/simplify` passes + two Pint passes | Cleanup gates |

## Open questions / TODOs to revisit

- Ask DK in 3-6 months whether they've added: (a) statement / recent-credits API, (b) webhook on inbound credit. If yes, design a v2 that eliminates the user's RRN paste step.
- Confirm during Task 0 whether the UAT environment lets us scan QRs from non-DK bank apps. If yes, add a fourth smoke test. If no, document the limitation and rely on production for first end-to-end cross-bank validation.
- Decide on the production MCC code with DK (currently defaulting to `5817` Digital Goods — may need to be `5734` Computer Software Stores or similar).
- Confirm the production `BASE_URL` once DK provides it. UAT URL: `https://internal-gateway.sit.digitalkidu.bt:8082/api/dkpg`.
