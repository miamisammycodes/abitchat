# Email Integration — Design

**Date:** 2026-05-21
**Status:** Approved (brainstorming complete; plan pending)
**Owner:** Solo dev (Sam) + Claude
**Related phases:** Foundation underneath Phase 17 (Team Mgmt), Phase 19 (Billing Completion), Phase 21 (Analytics & Notifications)

---

## Problem

The system has no working email delivery. `MAIL_MAILER=log` writes every email to `storage/logs/laravel.log`; nothing leaves the machine. Two notification classes exist (`NewLeadNotification`, `EnterpriseInquiryNotification`) and silently land in the log. Payment receipts are pull-only (download from `/billing/transactions/{id}/receipt`) — clients have no notification that payment succeeded. Password reset routes are wired but the email is generic Laravel and goes to log.

The v1.1 roadmap (Phases 17/19/21) depends on transactional email working: team invites, cancellation confirmation, dunning sequence, quota warnings, weekly digest. None of those phases can ship their email pieces until the foundation is in place.

## Goal

Build the email-delivery foundation once, with Resend as provider, Mailpit as the local dev mailbox, and a recipient-resolver abstraction that future phases (especially Phase 21's notification-preferences feature) can drop into without rework. Ship the four user-facing emails whose triggers already exist in the codebase: payment receipt, lead notification, enterprise inquiry, password reset.

## Non-goals

- Emails for features that don't exist yet (team invite, cancellation, dunning, quota warning, weekly digest). Those phases own their own emails — this phase only declares their `EmailType` enum cases.
- Per-tenant sender branding. All emails come from `AbitChat <noreply@abit.bt>`.
- Notification preferences table + toggle UI (Phase 21).
- Internationalization (Dzongkha) — English only.
- Marketing or broadcast email infrastructure (admin-broadcast belongs to Phase 16).
- Resend webhook handling (bounced / complained / delivered events) — manual via Resend dashboard for v1.
- Open/click tracking.
- `List-Unsubscribe` header — transactional-only, not required.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Provider | **Resend** via `resend/resend-laravel` (Laravel 13 native `resend` mailer) | Modern API, free tier covers v1 volume, simple DNS setup |
| Local dev mailbox | **Mailpit** in Docker (SMTP `1025`, web UI `8025`) | Standard Laravel dev tool; HTML preview; no live SMTP from CI |
| Template strategy | **Themed Laravel markdown notifications** (`vendor:publish --tag=laravel-mail`) | Single branded layout; markdown bodies stay fast to author |
| Sender identity | `AbitChat <noreply@abit.bt>` with reply-to `support@abit.bt` (per-email overrides allowed) | Single domain to verify in Resend; one DKIM/SPF setup |
| Notification preferences | **Defer to Phase 21**, but design hook today | All v1 emails are operational/transactional — no legal opt-out required. `RecipientResolver` is the single chokepoint Phase 21 swaps. |
| Queue | Reuse existing `database` driver + tenant-aware `SendQueuedMailable` mapping in `config/multitenancy.php` | Already wired |
| Recipient routing v1 | **Tenant owners only** for tenant-facing emails; admin email (env var) for inquiries; raw email for invites/password resets | Single role-tier — manager/agent additions become a Phase 21 preference |

## Architecture

### Provider & dev mailbox

**Production** (`MAIL_MAILER=resend`):
- `composer require resend/resend-laravel`
- Env: `RESEND_KEY=re_...`
- `config/mail.php`: add `resend` mailer (Resend package auto-registers it)
- DNS on `abit.bt`: DKIM TXT records per Resend dashboard; SPF (`v=spf1 include:_spf.resend.com ~all`)

**Local dev** (`MAIL_MAILER=smtp`):
- `docker run -d -p 1025:1025 -p 8025:8025 axllent/mailpit`
- `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, `MAIL_USERNAME=null`, `MAIL_PASSWORD=null`
- Browser: http://localhost:8025
- Documented in `ONBOARDING.md` and project `README` (one-paragraph setup section each)

**CI / tests:** `Mail::fake()` / `Notification::fake()`. Never touches a live mailer.

### Sender identity

- From: `AbitChat <noreply@abit.bt>` (set via `MAIL_FROM_ADDRESS` + `MAIL_FROM_NAME`)
- Reply-to default: `support@abit.bt`
- Per-email overrides:
  - `NewLeadNotification` → reply-to = the lead's own email if present, else `support@abit.bt` (lead email is nullable)
  - `EnterpriseInquiryNotification` → reply-to = the inquirer's email
  - `TeamInviteNotification` (Phase 17) → reply-to = inviting owner's email

### Template branding

Publish Laravel's mail views once:

```
php artisan vendor:publish --tag=laravel-mail
```

Customize:
- `resources/views/vendor/mail/html/themes/abitchat.css` — accent color `#22c55e` (matches the receipt PDF), font stack matches dashboard
- `resources/views/vendor/mail/html/header.blade.php` — "AbitChat" wordmark
- `resources/views/vendor/mail/html/footer.blade.php` — `support@abit.bt` · "AI-Powered Chatbot SaaS · Thimphu, Bhutan"
- `config/mail.php`: `'markdown' => ['theme' => 'abitchat']`

### Components

**`App\Enums\EmailType`** — backed string enum, single source of truth for every email type the system can send. v1 declares cases for both the 4 in-scope and the 5 deferred emails so Phase 17/19/21 don't need to extend the enum:

```php
namespace App\Enums;

enum EmailType: string
{
    case Receipt           = 'receipt';
    case LeadNotification  = 'lead_notification';
    case EnterpriseInquiry = 'enterprise_inquiry';
    case PasswordReset     = 'password_reset';
    // Declared now; senders ship with their owner-phase:
    case TeamInvite        = 'team_invite';        // Phase 17
    case Cancellation      = 'cancellation';       // Phase 19
    case Dunning           = 'dunning';            // Phase 19
    case QuotaWarning      = 'quota_warning';      // Phase 19
    case WeeklyDigest      = 'weekly_digest';      // Phase 21
}
```

**`App\Services\Email\RecipientResolver`** — the single chokepoint that decides who receives a given `EmailType` for a tenant:

```php
public function recipientsFor(EmailType $type, ?Tenant $tenant = null): Collection;
```

v1 hardcoded rules:
- `Receipt`, `Cancellation`, `Dunning`, `QuotaWarning`, `WeeklyDigest`, `LeadNotification` → tenant owners — concretely, `User::query()->whereHas('roles', fn ($q) => $q->where('tenant_id', $tenant->id)->where('role', Role::Owner))->get()` using the `user_roles` pivot from Phase 16.1
- `EnterpriseInquiry` → admin address from `config('mail.admin_inquiry_address')` (returned as a single anonymous-notifiable wrapper)
- `TeamInvite`, `PasswordReset` → resolved by caller (invitee's raw email; the requesting `User`) — these are not asked of the resolver. Calling `recipientsFor(TeamInvite, …)` or `recipientsFor(PasswordReset, …)` throws `\LogicException` to make the misuse obvious.

Phase 21 swaps this implementation to consult a `notification_preferences` table. Notification classes, triggers, and templates remain untouched.

**Notification classes (in scope this PR):**

| Class | Namespace | Notes |
|---|---|---|
| `PaymentReceiptNotification` | `App\Notifications\Billing` | NEW. Attaches PDF via `ReceiptService::generatePdf()`. Implements `ShouldQueue`. |
| `NewLeadNotification` (move + refactor) | `App\Notifications\Leads` | Route via `RecipientResolver`. Reply-to = lead's email. |
| `EnterpriseInquiryNotification` (move + refactor) | `App\Notifications\Admin` | Route via `RecipientResolver`. Reply-to = inquirer's email. |
| `ResetPasswordNotification` | `App\Notifications\Auth` | Extends Laravel's `Illuminate\Auth\Notifications\ResetPassword`; uses themed `MailMessage`. Override `User::sendPasswordResetNotification()` to dispatch this one. |

### Triggers / wiring

| Email | Trigger site | Dispatch pattern |
|---|---|---|
| Receipt | `Transaction::approveAndActivate()` inside a `DB::afterCommit(fn () => Notification::send($recipients, new PaymentReceiptNotification($tx)))` | The afterCommit ensures rollback does not email. |
| Lead | `LeadService::notifyNewLead()` — swap `$user->notify(...)` for `Notification::send(resolver(LeadNotification, $tenant), new NewLeadNotification($lead))` | Same recipient semantics as today; through resolver |
| Enterprise inquiry | `EnterpriseInquiryController::store()` — swap `Notification::route('mail', $hardcoded)` for resolver | `config('mail.admin_inquiry_address')` defaults `env('ADMIN_INQUIRY_EMAIL', 'support@abit.bt')` |
| Password reset | Override `User::sendPasswordResetNotification($token)` → `$this->notify(new ResetPasswordNotification($token))` | Laravel's password broker still drives the flow |

### Data flow (receipt example)

1. Client pays via DK QR; status poll detects `paid`
2. `DkBankQrService::interpretStatusResponse()` calls `$transaction->approveAndActivate()`
3. Inside `approveAndActivate()`, after the status flip succeeds, `DB::afterCommit(...)` schedules:
   - `Notification::send($resolver->recipientsFor(EmailType::Receipt, $tx->tenant), new PaymentReceiptNotification($tx))`
4. Notification is pushed to the tenant-aware queue (`SendQueuedMailable` mapping)
5. Queue worker processes the job; renders the `MailMessage` with the themed layout; attaches the PDF via `ReceiptService::generatePdf($tx)`
6. Email leaves via Resend in prod or Mailpit in dev

### Configuration

`config/mail.php` additions:
- `'admin_inquiry_address' => env('ADMIN_INQUIRY_EMAIL', 'support@abit.bt')`
- `'markdown' => ['theme' => 'abitchat', 'paths' => [resource_path('views/vendor/mail')]]`

`.env.example` updated:
- `MAIL_MAILER=smtp` → keep (Mailpit default)
- New: `RESEND_KEY=`
- New: `ADMIN_INQUIRY_EMAIL=`

`.env` (local) — switch from `log` to `smtp` for Mailpit.

## File map

**New files:**
- `app/Enums/EmailType.php`
- `app/Services/Email/RecipientResolver.php`
- `app/Notifications/Billing/PaymentReceiptNotification.php`
- `app/Notifications/Auth/ResetPasswordNotification.php`
- `resources/views/vendor/mail/html/themes/abitchat.css` plus the rest of the published mail views from `vendor:publish --tag=laravel-mail` (header, footer, layout, button — ~6 blade files), most untouched from defaults
- `tests/Unit/Services/Email/RecipientResolverTest.php`
- `tests/Feature/Email/PaymentReceiptTest.php`
- `tests/Feature/Email/PasswordResetEmailTest.php`

**Modified files (~10):**
- `app/Models/Transaction.php` — `approveAndActivate()` dispatches receipt notification via `DB::afterCommit`
- `app/Models/User.php` — override `sendPasswordResetNotification`
- `app/Services/Leads/LeadService.php` — route through `RecipientResolver`
- `app/Http/Controllers/Client/EnterpriseInquiryController.php` — route through `RecipientResolver`
- `app/Notifications/NewLeadNotification.php` → move to `app/Notifications/Leads/`
- `app/Notifications/EnterpriseInquiryNotification.php` → move to `app/Notifications/Admin/`
- `config/mail.php` — theme + admin_inquiry_address
- `.env.example` — RESEND_KEY, ADMIN_INQUIRY_EMAIL
- `composer.json` — `resend/resend-laravel`
- `ONBOARDING.md` and `README` — Mailpit setup paragraph
- `tests/Feature/Email/EnterpriseInquiryEmailTest.php` (existing or new — assert resolver routing)
- `tests/Feature/Email/LeadNotificationTest.php` (existing — update for resolver + reply-to)

## Testing strategy

**Unit:**
- `RecipientResolverTest` — for each `EmailType`:
  - returns owners-only for a tenant with 1 owner
  - returns multiple owners for a tenant with 2 owners
  - returns empty Collection for a tenant with 0 owners (no exception)
  - `EnterpriseInquiry` returns the configured admin address
  - `TeamInvite` / `PasswordReset` throw (caller-resolved; resolver should not be asked)

**Feature:**
- `PaymentReceiptTest`:
  - `Mail::fake()`; `$tx->approveAndActivate()` dispatches `PaymentReceiptNotification` to owners
  - Notification attaches the PDF (assert `attachments()` returns a non-empty array)
  - Rollback case — wrap `approveAndActivate()` in a transaction that rolls back; assert NO notification sent (covers `DB::afterCommit` correctness)
- `NewLeadNotificationTest` (refactor existing) — assert reply-to = lead email, recipients via resolver
- `EnterpriseInquiryNotificationTest` (refactor existing) — assert routed to `config('mail.admin_inquiry_address')`
- `PasswordResetEmailTest` — POST to `/forgot-password`, assert `ResetPasswordNotification` queued for that user with the reset URL

**Rendering snapshot:**
- Per email: render `toMail($notifiable)->render()` to HTML; assert key strings present (plan name, BTN amount, reply-to address, CTA URL). Catches template regressions.

**All tests use `Mail::fake()` / `Notification::fake()`** — never touches live SMTP.

**Manual smoke (PR body checklist):**
- Mailpit running locally
- Trigger payment approval → email visible at http://localhost:8025 with PDF attached
- Trigger lead capture → email visible
- Submit enterprise inquiry → email visible
- Request password reset → email visible with branded layout

## Phase 21 readiness hook

When Phase 21 ships notification preferences:
1. Add `notification_preferences` table (tenant_id, user_id, email_type, enabled) — Phase 21's migration
2. Add the toggles UI page — Phase 21's frontend
3. Rewrite `RecipientResolver::recipientsFor()` to filter the owner collection by the preferences table for the given `EmailType`
4. No other file in this PR changes

Sanity check: every place this PR dispatches a notification goes through the resolver. Greppable invariant: `Notification::send(` should appear only paired with `$resolver->recipientsFor(`. No direct `User::notify(new XNotification())` for the 4 emails owned by this PR.

## Deploy steps (for the PR body)

1. `composer install` — pulls in `resend/resend-laravel`
2. Verify `abit.bt` in Resend dashboard:
   - Add DKIM TXT records from Resend dashboard to `abit.bt` DNS
   - Wait for verification (usually <15 min)
3. Set prod env vars:
   - `MAIL_MAILER=resend`
   - `RESEND_KEY=re_...`
   - `MAIL_FROM_ADDRESS=noreply@abit.bt`
   - `MAIL_FROM_NAME=AbitChat`
   - `ADMIN_INQUIRY_EMAIL=support@abit.bt`
4. Confirm queue worker running: `php artisan queue:work --tries=3 --queue=default` (already in place; verify supervisor)
5. Smoke from tinker on prod:
   - `Mail::raw('smoke test', fn ($m) => $m->to('sam@abit.bt')->subject('Smoke'));`
   - Confirm receipt within ~30 seconds
6. Optionally deploy with `MAIL_MAILER=log` first; flip to `resend` once DNS verified. Rollback: set `MAIL_MAILER=log`.

## Risks / mitigations

| Risk | Mitigation |
|---|---|
| Resend domain not yet verified on deploy → all emails 401-fail in production | Deploy with `MAIL_MAILER=log` first; verify domain via Resend dashboard; flip to `resend` once green. |
| PDF attachment size — receipts are currently ~880 KB | Resend's hard limit is 40 MB; ~46× headroom. Monitor when receipt template grows. |
| Tenant with zero owners (edge case after offboarding) | `RecipientResolver` returns empty Collection; `Notification::send(empty, ...)` is a no-op; log a warning so we notice |
| Existing log-only behavior was masking notification bugs | Snapshot rendering tests + manual smoke catch this before deploy |
| Sending receipts on test transactions (admin-created `seed` data) | Receipts only fire on `approveAndActivate()` from real DK QR flow; seeds insert with `status='approved'` directly and don't hit the trigger. Document the trigger boundary. |

## Open questions (none blocking)

- Should `ADMIN_INQUIRY_EMAIL` be a comma-separated list to alert multiple admins? Not now; one address fine for v1.
- Reply-to for `PaymentReceiptNotification` — default `support@abit.bt` or something billing-specific? Default to `support@abit.bt`.

## References

- Resend Laravel docs: https://resend.com/docs/send-with-laravel
- Mailpit: https://mailpit.axllent.org/docs/
- Laravel 13 Mail: stays compatible with Notification's `MailMessage`
- PRD references: PRD § Notifications, REQ-cnc-* family
- Roadmap: `.planning/ROADMAP.md` Phase 17/19/21
