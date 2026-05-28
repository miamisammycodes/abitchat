# Free Plan Activation & Tenant Lifecycle — Design

**Date:** 2026-05-28
**Status:** Awaiting spec review
**Author:** Sameer + Claude

## Problem

Today a newly registered tenant is silently put on a 14-day implicit trial
(`trial_ends_at = now()+14d`, no plan) and the widget works immediately via
`CheckUsageLimits` → `config('billing.trial_limits')`. Two problems:

1. **The clock starts at registration.** A tenant who registers to set things
   up — add a few knowledge items first, go live later — burns trial days
   before they ever use the product.
2. **There is no gate on going live.** The api_key is generated and revealed on
   the Widget Settings page at registration, so "having an account" and "having
   a live, billable widget" are conflated.

We also have no defined behavior for what happens **after** the free window
ends, and no user-facing notification that it's ending.

## Decision: an explicit tenant lifecycle, with activation decoupled from registration

Registration creates an **inert Setup account**. The tenant explicitly clicks
**"Start Free Plan"** to begin the 14-day window, which is also the moment the
api_key + embed snippet are revealed and the widget goes live. After 14 days the
tenant drops to a **view-only Expired** state until they subscribe to a paid plan.

### Lifecycle states (new tenants)

| State | Condition | KB management | Widget API | api_key in dashboard | Usage strip limits |
|-------|-----------|---------------|------------|----------------------|--------------------|
| **Setup** | `plan_id` null, `trial_ends_at` null | ✅ allowed, capped at **Free** (10 KB) | ⛔ blocked | hidden — "Start Free Plan" CTA | Free plan |
| **Active** | `plan_id` set, not expired | ✅ allowed, capped at plan | ✅ allowed | revealed + embed snippet | current plan |
| **Expired** | `plan_id` set, expired | ⛔ view/export only | ⛔ blocked | hidden — "Subscribe" CTA | current plan (display only) |
| **LegacyTrial** | `plan_id` null, `trial_ends_at` set | unchanged (existing behavior) | unchanged | unchanged | `trial_limits` |

`LegacyTrial` exists only for tenants registered under the old model; it folds
into Active (while `isOnTrial()`) or Expired (after) for gating purposes and is
otherwise left untouched (no backfill).

### Decisions

| # | Decision | Choice |
|---|----------|--------|
| D1 | When does the trial clock start? | On an explicit **"Start Free Plan"** click — NOT at registration |
| D2 | Window length | **14 days** |
| D3 | What can a Setup tenant do? | Full setup: build KB (capped at Free's 10), configure widget, crawl site. No going live. |
| D4 | What unlocks the api_key + widget? | Reaching **Active** (started Free, or subscribed to a paid plan) |
| D5 | After Free expires | **View-only**: can view/export leads, conversations, KB; cannot add/edit KB or use the widget. Data retained. |
| D6 | Restart Free after expiry? | No — `trial_activated_at` one-shot guard. Must subscribe to a paid plan. |
| D7 | Notifications | In-app countdown banner (Active) + Expired banner; emails on start, ~3 days before expiry, and on expiry |
| D8 | api_key generation | Generated at registration (model/hash/cache machinery needs it) but **hidden** until Active. Functional block on the widget API is the second layer. |
| D9 | Initial crawl | Still dispatched at registration (part of setup). Crawl-created KB items bypass the cap — pre-existing behavior, out of scope. |
| D10 | Existing tenants | No migration/backfill. Legacy tenants **still on** an active implicit trial are unchanged. Legacy tenants whose implicit trial has **already lapsed** roll into Expired and gain the new view-only KB-write gate (they were already widget-blocked, so this only adds the KB-write restriction). |
| D11 | Paid-plan lapse | A paid tenant whose `plan_expires_at` lapses also falls into Expired (same accessor) → same view-only KB-write gate until they renew/subscribe. Intended. |

## Architecture

### `Tenant::lifecycleState(): TenantLifecycle`

New PHP enum `App\Enums\TenantLifecycle { Setup, Active, Expired, LegacyTrial }`
and an accessor on `Tenant`:

```
plan_id set & !isPlanExpired()              → Active
plan_id set &  isPlanExpired()              → Expired
plan_id null & trial_ends_at set & onTrial  → LegacyTrial (treated as Active for gating)
plan_id null & trial_ends_at set & !onTrial → Expired
plan_id null & trial_ends_at null           → Setup
```

This accessor is the single source of truth for all gating and UI state.

### Registration → Setup

`RegisterController::store` creates a bare tenant: `status=active`, **no**
`plan_id`, **no** `plan_expires_at`, **no** `trial_ends_at`, **no**
`trial_activated_at`. (The `Tenant created` debug log is made null-safe.) The
initial crawl still dispatches if a `website_url` was supplied (D9).

### "Start Free Plan" → Active

The repurposed activation action (the old `activateTrial` logic, **kept** and
renamed `startFreePlan`):
- Authorizes `ManageBilling`.
- Looks up the Free plan (`slug='free'`, `price=0`); `abort(404)`-style flash
  error if missing (guard, not a fallback — Free is always seeded).
- One-shot guard: `trial_activated_at !== null` → flash "already used".
- Already on an active plan → flash "already have a plan".
- Sets `plan_id=Free`, `plan_expires_at=now()+Tenant::FREE_TRIAL_DAYS`,
  `trial_activated_at=now()`.
- Fires the "trial started" email.

Route moves from the pricing page to a dedicated `POST /billing/start-free-plan`
named `client.billing.start-free-plan`.

### api_key gating

`WidgetController::index` reveals `api_key` (and the embed snippet) only when
`lifecycleState() === Active`. Setup → locked panel with "Start Free Plan";
Expired → locked panel with "Subscribe". A new prop `widgetUnlocked: bool`
drives `Pages/Client/Widget/Index.vue`.

### Gating mechanics

**Widget API (`conversations`/`tokens`/`leads`):** already requires Active via
the existing `CheckUsageLimits` subscription check (`!hasPlan() && !isOnTrial()`
→ 403 `NO_SUBSCRIPTION`). New Setup tenants (no plan, no trial) are blocked by
this automatically — **no change needed** for widget gating. (Note: `/init`
itself is not usage-gated, but it is inert in Setup — the api_key is hidden so
no embed exists, and the first `/conversation` call is blocked. Acceptable; not
adding a gate to `/init`.)

**KB create (`check.limits:knowledge_items` on `knowledge.store`):** modified so
the subscription check does **not** fire for `knowledge_items` in Setup; the cap
check still runs (Setup uses Free limits via `limitsFor`). Expired → blocked with
a "subscribe to make changes" message.

**KB mutation (`update`/`destroy`/`reprocess`/`retry`):** these have no limit
middleware today. Add middleware `block.expired` (alias for
`EnsureNotExpired`) on these routes: allows Setup + Active, redirects Expired to
`client.billing.plans` with a flash error. (Scope is deliberately limited to KB
writes — the faithful reading of D5 "can't add/edit knowledge." Leads/conversation
management and widget-settings appearance edits are not blocked.)

### `UsageTracker::limitsFor` (display, decoupled from gating)

- `plan_id` set → that plan's limits (whether or not expired — so an Expired
  tenant's strip shows their plan's numbers, not `trial_limits`).
- else `trial_ends_at` set → `trial_limits` (LegacyTrial only).
- else (Setup) → **Free plan limits** (preview).

Gating is enforced by `lifecycleState()` + middleware, never by `limitsFor`.

### Notifications (Phase 2)

**Banner** (`HandleInertiaRequests` shares a `trialStatus` prop):
- Active: "Your Free plan expires in N days — subscribe to stay live."
- Expired: "Your Free plan ended — subscribe to reactivate your widget."

**Email (Resend)** — three new `EmailType` enum entries + notifications:
- `trial_started` — sent by `startFreePlan`.
- `trial_expiring` — reminder ~3 days before `plan_expires_at`.
- `trial_expired` — on/after expiry.

**Scheduled command** `trials:send-lifecycle-emails` (registered in
`routes/console.php` daily, alongside `crawls:refresh-all`): finds Free-plan
tenants expiring in ~3 days (not yet reminded) and freshly-expired tenants (not
yet notified), sends the mails, and stamps idempotency columns.

## Data model

Existing columns used: `plan_id`, `plan_expires_at`, `trial_activated_at`,
`trial_ends_at`, `status`.

**New migration** adds two nullable timestamps to `tenants` for email
idempotency:
- `trial_expiring_notified_at`
- `trial_expired_notified_at`

No backfill of existing tenants.

## File map

| File | Change |
|------|--------|
| `app/Enums/TenantLifecycle.php` | **New** enum: Setup, Active, Expired, LegacyTrial |
| `app/Models/Tenant.php` | Add `FREE_TRIAL_DAYS=14` const + `lifecycleState()` accessor + 2 new fillable/cast timestamps |
| `database/migrations/xxxx_add_trial_notification_timestamps_to_tenants.php` | **New** — 2 nullable timestamps |
| `app/Http/Controllers/Auth/RegisterController.php` | Create bare Setup tenant (no plan/trial); null-safe log |
| `app/Http/Controllers/Client/BillingController.php` | Rename `activateTrial` → `startFreePlan`; fire `trial_started` email |
| `routes/web.php` | Replace `activate-trial/{plan}` with `start-free-plan` (no plan param) |
| `app/Http/Middleware/CheckUsageLimits.php` | Allow Setup for `knowledge_items` (cap Free); block Expired |
| `app/Http/Middleware/EnsureNotExpired.php` | **New** — block KB-write routes when Expired |
| `bootstrap/app.php` | Register `block.expired` middleware alias |
| `app/Services/Usage/UsageTracker.php` | `limitsFor`: Setup → Free limits; plan_id-set → plan limits regardless of expiry |
| `app/Http/Controllers/Client/WidgetController.php` | Reveal api_key only when Active; add `widgetUnlocked` prop. Also gate `regenerateApiKey` to Active (no-op/forbidden in Setup/Expired — regenerating a hidden key is a footgun). |
| `app/Http/Controllers/Client/DashboardController.php` | Stop leaking the truncated api_key hint unless Active (currently shows `substr(api_key,0,8).'...'` in all states — contradicts D8). |
| `resources/js/Pages/Client/Widget/Index.vue` | Locked state + "Start Free Plan"/"Subscribe" CTA when not unlocked |
| `resources/js/Pages/Client/Billing/Plans.vue` | Free card has **three** states: "Start Free Plan" (Setup), "Current Plan"/disabled (Active on Free), and "Trial used — choose a paid plan"/disabled (Expired or `trial_activated_at` set). Remove old activate-trial wiring. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share `trialStatus` (state + days remaining) |
| `resources/js/Layouts/ClientLayout.vue` (or banner cmpt) | Countdown / expired banner |
| `app/Enums/EmailType.php` | Add `trial_started`, `trial_expiring`, `trial_expired` |
| `app/Notifications/Billing/*` | **New** 3 notifications |
| `app/Console/Commands/SendTrialLifecycleEmails.php` | **New** scheduled command |
| `routes/console.php` | Schedule `trials:send-lifecycle-emails` daily |
| tests | See test plan |

## Validation rules

- Free plan resolved by `slug='free'` AND `price=0`.
- `startFreePlan` is idempotent via the `trial_activated_at` one-shot guard.
- Window length read from `Tenant::FREE_TRIAL_DAYS` everywhere (no duplication).
- Email sends are idempotent via the two `*_notified_at` columns.

## Phasing

- **Phase 1 — Lifecycle + activation gating:** enum, accessor, registration→Setup,
  startFreePlan + route, CheckUsageLimits + EnsureNotExpired, limitsFor, api_key
  reveal gating, Widget/Plans UI. Ships a working, testable behavior on its own.
- **Phase 2 — Notifications:** migration, `trialStatus` prop + banners, 3 emails,
  scheduled command.

Each phase is independently shippable; Phase 2 depends on Phase 1's states.

## Out of scope

- Backfilling/migrating existing (LegacyTrial) tenants.
- Removing legacy `trial_limits` config / `isOnTrial()` (still load-bearing).
- Reconciling crawl-created KB items against the Free cap (pre-existing).
- Blocking non-KB mutations when Expired (leads/conversations/appearance stay
  editable; only KB writes + widget are gated by D5).
- Payment-flow / DK Bank / Stripe changes.
- Proration, multiple concurrent trials, or per-tenant custom trial lengths.

## Testing note (carry into the plan)

`Tests\TestCase::createTenantWithUser()` sets `trial_ends_at = now()+14d`, so
`actingAsTenantUser()` yields a **LegacyTrial** tenant, **not** a Setup tenant.
Tests for Setup behavior (KB cap = Free pre-start, widget blocked, key hidden)
must build a tenant with **no** `trial_ends_at` and **no** `plan_id` — add a
helper (e.g. `actingAsSetupTenant()`) or use `Tenant::factory()` (which does not
set those fields) + manual user/role rows. Flag this in the plan's "key facts"
so the implementer doesn't chase phantom failures.

## Test plan (smoke, post-implementation)

1. Register → land on dashboard in **Setup**: usage strip shows Free limits
   (/100, /50, /10, /50k); Widget Settings shows locked panel + "Start Free
   Plan", **no api_key**.
2. Add 3 knowledge items in Setup → allowed; clock has not started
   (`trial_activated_at` null).
3. Click "Start Free Plan" → **Active**: api_key + embed snippet revealed;
   widget answers a message; "expires in 14 days" banner shows; `trial_started`
   email in Mailpit.
4. Set `plan_expires_at` to the past → **Expired**: widget returns 403; Widget
   Settings hides the key + shows "Subscribe"; KB create/edit blocked (redirect
   to billing); leads/conversations still viewable + exportable.
5. Run `php artisan trials:send-lifecycle-emails` against a tenant expiring in 3
   days and a freshly-expired tenant → reminder + expired emails sent once
   (re-running sends nothing).
6. Subscribe to a paid plan from Expired → back to Active; widget live again.
