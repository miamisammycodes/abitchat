# Email Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire Resend as the production email provider, Mailpit as the local dev mailbox, themed Laravel markdown notifications, an `EmailType` enum + `RecipientResolver` abstraction, and ship four user-facing emails (payment receipt, lead notification, enterprise inquiry, password reset).

**Architecture:** Notifications-centric. Every recipient decision routes through `App\Services\Email\RecipientResolver`, the single chokepoint Phase 21 will swap for a preferences-aware implementation. Receipts dispatch from inside `Transaction::approveAndActivate()` via `DB::afterCommit` so a rollback never emails. Existing notification classes (`NewLeadNotification`, `EnterpriseInquiryNotification`) are moved into subnamespaces and re-routed through the resolver.

**Tech Stack:** Laravel 13 / PHP 8.4 · `resend/resend-laravel` (Resend SDK) · Mailpit Docker (dev) · Laravel markdown mail · Phase 16.1's `UserRole` pivot + `Role` enum · `barryvdh/laravel-dompdf` for receipt PDF attachment

**Spec:** `docs/superpowers/specs/2026-05-21-email-integration-design.md`

**Codebase conventions (recap — must follow):**
- PHPStan baseline must remain 0 (`./vendor/bin/phpstan analyse`)
- Pint must be clean (`./vendor/bin/pint --test`)
- Run `php artisan test` (full suite, not just feature-scoped) between tasks
- Tenant-scoped queries: use `Model::forTenant($tenant)`, never raw `where('tenant_id', …)` — the `NoRawTenantIdWhere` Larastan rule blocks it
- New tenant-scoped models get the `BelongsToTenant` trait; usage-counted models also get `BustsTenantUsageCache`
- Notifications already implement `ShouldQueue` — keep that pattern
- Tests use `Mail::fake()` / `Notification::fake()` — never live SMTP

---

## File Structure

**New files:**

| Path | Responsibility |
|---|---|
| `app/Enums/EmailType.php` | Backed string enum: every email type the system can send (9 cases, 4 active + 5 deferred) |
| `app/Services/Email/RecipientResolver.php` | Single chokepoint mapping `EmailType` → `Collection<User>` (or anonymous notifiable). Today: hardcoded. Phase 21: preferences-aware. |
| `app/Notifications/Billing/PaymentReceiptNotification.php` | Receipt email triggered by `Transaction::approveAndActivate`. Attaches PDF. |
| `app/Notifications/Auth/ResetPasswordNotification.php` | Branded password reset extending Laravel's built-in. |
| `resources/views/vendor/mail/html/themes/abitchat.css` | Themed CSS — `#22c55e` accent, AbitChat font stack |
| `resources/views/vendor/mail/html/header.blade.php` | AbitChat wordmark header (replaces Laravel default) |
| `resources/views/vendor/mail/html/footer.blade.php` | `support@abit.bt` + "AI-Powered Chatbot SaaS · Thimphu, Bhutan" |
| `tests/Unit/Services/Email/RecipientResolverTest.php` | Resolver routing per EmailType |
| `tests/Feature/Email/PaymentReceiptTest.php` | approveAndActivate dispatches receipt; rollback does not |
| `tests/Feature/Email/PasswordResetEmailTest.php` | Branded reset notification dispatched, URL renders |
| `tests/Feature/Email/EmailRenderingSnapshotTest.php` | Render each notification → HTML and assert key strings |

**Moved + modified:**

| Old path | New path | Why |
|---|---|---|
| `app/Notifications/NewLeadNotification.php` | `app/Notifications/Leads/NewLeadNotification.php` | Subnamespace by domain (matches the new pattern) |
| `app/Notifications/EnterpriseInquiryNotification.php` | `app/Notifications/Admin/EnterpriseInquiryNotification.php` | Subnamespace by domain |

**Modified:**
- `app/Models/Transaction.php` — `approveAndActivate()` schedules receipt via `DB::afterCommit`
- `app/Models/User.php` — override `sendPasswordResetNotification($token)`
- `app/Services/Leads/LeadService.php` — `notifyNewLead()` routes via `RecipientResolver`, reply-to logic moves into notification class
- `app/Http/Controllers/Client/EnterpriseInquiryController.php` — switch to `RecipientResolver`
- `config/mail.php` — add `markdown.theme = 'abitchat'` and `admin_inquiry_address`
- `.env` (local) — switch `MAIL_MAILER=log` → `MAIL_MAILER=smtp` (Mailpit)
- `.env.example` — `MAIL_MAILER=smtp`, add `RESEND_KEY`, `ADMIN_INQUIRY_EMAIL`
- `composer.json` / `composer.lock` — `resend/resend-laravel`
- `README.md` — Mailpit local-dev section
- `ONBOARDING.md` — Mailpit local-dev section
- `tests/Feature/...` — any existing lead/inquiry email tests updated for new namespaces and resolver routing

---

## Task 0: Verification probe (no code changes)

**Purpose:** Confirm provider details, port numbers, and current notification call sites before writing code that depends on them.

- [ ] **Step 1: Verify Resend Laravel SDK package name + driver name**

Run:
```bash
composer search resend-laravel
```
Expected: package `resend/resend-laravel` listed. Confirm latest version on packagist.

Also verify `config/mail.php` already supports `resend` driver: read line containing `'Supported:'` comment — should list `"resend"` among drivers.

- [ ] **Step 2: Verify Mailpit Docker image and ports**

Run:
```bash
docker pull axllent/mailpit
```
Expected: pull completes. Image exposes ports `1025` (SMTP) and `8025` (HTTP UI).

- [ ] **Step 3: Verify `Transaction::approveAndActivate` signature**

Read `app/Models/Transaction.php` and locate the method. Confirm signature is:
```php
public function approveAndActivate(
    array $allowedFromStatuses,
    ?int $adminId = null,
    ?string $adminNotes = null,
): void
```
Confirm body wraps logic in `DB::transaction(function () { ... })`.

- [ ] **Step 4: Verify Phase 16.1 role lookup pattern**

Read `app/Models/UserRole.php`. Confirm `use BelongsToTenant;` is present and that the model has columns `tenant_id`, `user_id`, `role`. Run a one-shot check:
```bash
php artisan tinker --execute='echo \App\Models\UserRole::forTenant(\App\Models\Tenant::first())->where("role", \App\Enums\Role::Owner->value)->count();'
```
Expected: prints an integer (may be 0 if no owners seeded; non-zero confirms the seeder runs).

- [ ] **Step 5: Verify existing email notification call sites are exactly two**

Run:
```bash
grep -rn "Notification::send\|->notify(new" app --include="*.php"
```
Expected output: two call sites — `LeadService::notifyNewLead` (`$user->notify(new NewLeadNotification…)`) and `EnterpriseInquiryController` (`Notification::route('mail', …)->notify(new EnterpriseInquiryNotification…)`). Any extra match means another call site exists and must be migrated too.

- [ ] **Step 6: Document findings in the plan (if any divergence)**

If any of the above don't match what the plan assumed (package name changed, signature different, third call site exists), STOP and update this plan before proceeding to Task 1. Otherwise continue.

No commit for Task 0.

---

## Task 1: Mailpit + Resend foundation (config + docs)

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `.env`, `.env.example`
- Modify: `config/mail.php`
- Modify: `README.md`, `ONBOARDING.md`

- [ ] **Step 1: Install Resend SDK**

Run:
```bash
composer require resend/resend-laravel
```
Expected: package installs, `composer.json` updated.

- [ ] **Step 2: Start Mailpit locally**

Run:
```bash
docker run -d --name chatbot-mailpit --restart unless-stopped -p 1025:1025 -p 8025:8025 axllent/mailpit
```
Open http://localhost:8025 in a browser — UI loads with "No mail" empty state.

- [ ] **Step 3: Update local `.env` for Mailpit**

Modify `.env` lines:
```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@abit.bt"
MAIL_FROM_NAME="AbitChat"
ADMIN_INQUIRY_EMAIL="support@abit.bt"
```

- [ ] **Step 4: Update `.env.example` to document required keys**

Modify `.env.example` lines (replacing existing MAIL block):
```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@abit.bt"
MAIL_FROM_NAME="AbitChat"
ADMIN_INQUIRY_EMAIL="support@abit.bt"

# Production: set MAIL_MAILER=resend and add RESEND_KEY
RESEND_KEY=
```

- [ ] **Step 5: Add `admin_inquiry_address` to `config/mail.php`**

Modify `config/mail.php` — after the existing `'from'` key add:
```php
    /*
    |--------------------------------------------------------------------------
    | Admin Inquiry Recipient
    |--------------------------------------------------------------------------
    */

    'admin_inquiry_address' => env('ADMIN_INQUIRY_EMAIL', 'support@abit.bt'),
```

- [ ] **Step 6: Smoke-test the SMTP wiring**

Run:
```bash
php artisan tinker --execute='\Mail::raw("smoke test " . now(), fn ($m) => $m->to("smoke@example.com")->subject("Smoke"));'
```
Expected: open http://localhost:8025 — one email named "Smoke" present.

- [ ] **Step 7: Add Mailpit setup to `README.md` and `ONBOARDING.md`**

Append to `README.md` (in the "Local Development" section if it exists, else create one):
```markdown
### Local email (Mailpit)

This project sends transactional email via Resend in production and Mailpit (a local SMTP catcher) in development.

Start Mailpit once:
\`\`\`bash
docker run -d --name chatbot-mailpit --restart unless-stopped \
  -p 1025:1025 -p 8025:8025 axllent/mailpit
\`\`\`

Mail UI: http://localhost:8025

Verify with:
\`\`\`bash
php artisan tinker --execute='\\Mail::raw("test", fn (\$m) => \$m->to("test@example.com")->subject("Test"));'
\`\`\`
```

Append the same paragraph to `ONBOARDING.md` under a "Local email" heading.

- [ ] **Step 8: Verify full test suite still green**

Run:
```bash
php artisan test
```
Expected: PASS for all existing tests (no behavior changed yet).

- [ ] **Step 9: Pint + commit**

Run:
```bash
./vendor/bin/pint --test
```
If clean: commit. If not: run `./vendor/bin/pint` and commit fixes together.

```bash
git add composer.json composer.lock .env.example config/mail.php README.md ONBOARDING.md
git commit -m "feat(email): wire Resend SDK + Mailpit dev mailbox

Installs resend/resend-laravel, adds admin_inquiry_address config,
documents Mailpit setup in README and ONBOARDING. Local .env switched
from log to SMTP at 127.0.0.1:1025.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

(Note: `.env` is gitignored — do NOT add it to the commit.)

---

## Task 2: Publish + theme Laravel mail views

**Files:**
- Create: `resources/views/vendor/mail/html/themes/abitchat.css` (and the rest of `vendor/mail/**` published by Laravel)
- Modify: `resources/views/vendor/mail/html/header.blade.php`
- Modify: `resources/views/vendor/mail/html/footer.blade.php`
- Modify: `config/mail.php`

- [ ] **Step 1: Publish Laravel's mail views**

Run:
```bash
php artisan vendor:publish --tag=laravel-mail
```
Expected: files appear under `resources/views/vendor/mail/{html,text}/`.

- [ ] **Step 2: Create the AbitChat theme CSS**

Create `resources/views/vendor/mail/html/themes/abitchat.css` by copying `default.css` from the same folder, then adjusting the accent + button colors. Replace the file contents with the published `default.css` and apply these targeted swaps (look for these CSS rules and update only the listed properties):

In the `.button-primary` selector:
```css
background-color: #22c55e;
border-bottom: 8px solid #22c55e;
border-left: 18px solid #22c55e;
border-right: 18px solid #22c55e;
border-top: 8px solid #22c55e;
```

In the `body, body *:not(html):not(style):not(br):not(tr):not(code)` selector, set:
```css
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
```

Leave all other CSS as the default. (This gives AbitChat green CTAs and a slightly modernized font stack while keeping Laravel's email-client compatibility heuristics.)

- [ ] **Step 3: Customize the header**

Replace the contents of `resources/views/vendor/mail/html/header.blade.php` with:
```blade
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span style="font-size: 22px; font-weight: 600; color: #22c55e;">AbitChat</span>
</a>
</td>
</tr>
```

- [ ] **Step 4: Customize the footer**

Replace the contents of `resources/views/vendor/mail/html/footer.blade.php` with:
```blade
@props([])
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p style="margin: 0; font-size: 12px;">Need help? Email <a href="mailto:support@abit.bt" style="color: #22c55e;">support@abit.bt</a></p>
<p style="margin: 4px 0 0 0; font-size: 12px; color: #999;">AbitChat · AI-Powered Chatbot SaaS · Thimphu, Bhutan</p>
</td>
</tr>
</table>
</td>
</tr>
```

- [ ] **Step 5: Set theme in `config/mail.php`**

Find the `'markdown' => [...]` block and update `theme`:
```php
'markdown' => [
    'theme' => 'abitchat',
    'paths' => [
        resource_path('views/vendor/mail'),
    ],
],
```

- [ ] **Step 6: Smoke-test the themed layout**

Run:
```bash
php artisan tinker --execute='\Illuminate\Support\Facades\Notification::route("mail", "smoke@example.com")->notifyNow(new \Illuminate\Notifications\AnonymousNotifiable);'
```

Actually, a simpler smoke — write a one-shot inline test:
```bash
php artisan tinker --execute='
$m = (new \Illuminate\Notifications\Messages\MailMessage)
  ->subject("Theme smoke")
  ->greeting("Hi there")
  ->line("Testing the abitchat theme.")
  ->action("Visit AbitChat", "https://abit.bt");
echo $m->render();
' | head -50
```
Expected: HTML output contains `<span style="font-size: 22px; font-weight: 600; color: #22c55e;">AbitChat</span>` and the button styles include `#22c55e`.

- [ ] **Step 7: Run the full suite**

Run:
```bash
php artisan test
```
Expected: PASS (no behavior change to existing tests).

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint --test
git add resources/views/vendor/mail config/mail.php
git commit -m "feat(email): theme Laravel mail views with AbitChat branding

Publishes Laravel mail views, adds abitchat CSS theme (green accent
matching the receipt PDF), customizes header (AbitChat wordmark) and
footer (support@abit.bt, Thimphu location). Sets markdown.theme in
config/mail.php.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: EmailType enum (TDD)

**Files:**
- Create: `app/Enums/EmailType.php`
- Create: `tests/Unit/Enums/EmailTypeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Enums/EmailTypeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EmailType;
use PHPUnit\Framework\TestCase;

class EmailTypeTest extends TestCase
{
    public function test_all_expected_cases_exist(): void
    {
        $expected = [
            'receipt', 'lead_notification', 'enterprise_inquiry', 'password_reset',
            'team_invite', 'cancellation', 'dunning', 'quota_warning', 'weekly_digest',
        ];

        $actual = array_map(fn (EmailType $case): string => $case->value, EmailType::cases());

        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_can_be_constructed_from_string_value(): void
    {
        $this->assertSame(EmailType::Receipt, EmailType::from('receipt'));
        $this->assertSame(EmailType::TeamInvite, EmailType::from('team_invite'));
    }

    public function test_invalid_string_returns_null_via_tryFrom(): void
    {
        $this->assertNull(EmailType::tryFrom('not_a_real_email'));
    }
}
```

- [ ] **Step 2: Run test and verify it fails**

Run:
```bash
php artisan test --filter=EmailTypeTest
```
Expected: FAIL with "Class App\Enums\EmailType not found".

- [ ] **Step 3: Implement `EmailType` enum**

Create `app/Enums/EmailType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum EmailType: string
{
    case Receipt = 'receipt';
    case LeadNotification = 'lead_notification';
    case EnterpriseInquiry = 'enterprise_inquiry';
    case PasswordReset = 'password_reset';

    // Declared now so Phase 17/19/21 don't need to extend the enum.
    // No sender ships in this PR.
    case TeamInvite = 'team_invite';
    case Cancellation = 'cancellation';
    case Dunning = 'dunning';
    case QuotaWarning = 'quota_warning';
    case WeeklyDigest = 'weekly_digest';
}
```

- [ ] **Step 4: Run test and verify it passes**

Run:
```bash
php artisan test --filter=EmailTypeTest
```
Expected: PASS — 3/3.

- [ ] **Step 5: Run full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All three: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/EmailType.php tests/Unit/Enums/EmailTypeTest.php
git commit -m "feat(email): add EmailType enum

Single source of truth for every email type the system can send.
Includes the four active types plus five deferred-to-feature-phase
types so 17/19/21 don't need to extend the enum.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: RecipientResolver (TDD)

**Files:**
- Create: `app/Services/Email/RecipientResolver.php`
- Create: `tests/Unit/Services/Email/RecipientResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Email/RecipientResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Enums\EmailType;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Email\RecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use LogicException;
use Tests\TestCase;

class RecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private RecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RecipientResolver;
    }

    public function test_returns_owners_for_tenant_facing_email_types(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        UserRole::create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'role' => Role::Owner,
        ]);

        $this->assertCount(1, $this->resolver->recipientsFor(EmailType::Receipt, $tenant));
        $this->assertCount(1, $this->resolver->recipientsFor(EmailType::LeadNotification, $tenant));
        $this->assertCount(1, $this->resolver->recipientsFor(EmailType::QuotaWarning, $tenant));
    }

    public function test_returns_multiple_owners_when_present(): void
    {
        $tenant = Tenant::factory()->create();
        $owner1 = User::factory()->create();
        $owner2 = User::factory()->create();
        foreach ([$owner1, $owner2] as $u) {
            UserRole::create([
                'user_id' => $u->id,
                'tenant_id' => $tenant->id,
                'role' => Role::Owner,
            ]);
        }

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenant);

        $this->assertCount(2, $recipients);
        $this->assertEqualsCanonicalizing(
            [$owner1->id, $owner2->id],
            $recipients->pluck('id')->all(),
        );
    }

    public function test_excludes_non_owner_roles(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $agent = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);
        UserRole::create(['user_id' => $manager->id, 'tenant_id' => $tenant->id, 'role' => Role::Manager]);
        UserRole::create(['user_id' => $agent->id, 'tenant_id' => $tenant->id, 'role' => Role::Agent]);

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenant);

        $this->assertCount(1, $recipients);
        $this->assertSame($owner->id, $recipients->first()->id);
    }

    public function test_returns_empty_collection_when_no_owners(): void
    {
        $tenant = Tenant::factory()->create();

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenant);

        $this->assertInstanceOf(Collection::class, $recipients);
        $this->assertCount(0, $recipients);
    }

    public function test_excludes_owners_of_other_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        UserRole::create(['user_id' => $ownerA->id, 'tenant_id' => $tenantA->id, 'role' => Role::Owner]);
        UserRole::create(['user_id' => $ownerB->id, 'tenant_id' => $tenantB->id, 'role' => Role::Owner]);

        $recipients = $this->resolver->recipientsFor(EmailType::Receipt, $tenantA);

        $this->assertCount(1, $recipients);
        $this->assertSame($ownerA->id, $recipients->first()->id);
    }

    public function test_enterprise_inquiry_returns_anonymous_notifiable_for_admin_address(): void
    {
        config(['mail.admin_inquiry_address' => 'admin@abit.bt']);

        $recipients = $this->resolver->recipientsFor(EmailType::EnterpriseInquiry);

        $this->assertCount(1, $recipients);
        $this->assertInstanceOf(AnonymousNotifiable::class, $recipients->first());
        $this->assertSame('admin@abit.bt', $recipients->first()->routeNotificationFor('mail'));
    }

    public function test_team_invite_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);
        $this->resolver->recipientsFor(EmailType::TeamInvite, Tenant::factory()->create());
    }

    public function test_password_reset_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);
        $this->resolver->recipientsFor(EmailType::PasswordReset);
    }
}
```

- [ ] **Step 2: Run test and verify it fails**

Run:
```bash
php artisan test --filter=RecipientResolverTest
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `RecipientResolver`**

Create `app/Services/Email/RecipientResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailType;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use LogicException;

class RecipientResolver
{
    /**
     * Resolve the recipients for a given email type.
     *
     * Returns either a Collection of User models (tenant-facing emails) or
     * a Collection containing a single AnonymousNotifiable wrapping a raw
     * email address (admin-facing emails).
     *
     * Phase 21 swaps this implementation to consult notification_preferences.
     *
     * @return Collection<int, User|AnonymousNotifiable>
     */
    public function recipientsFor(EmailType $type, ?Tenant $tenant = null): Collection
    {
        return match ($type) {
            EmailType::Receipt,
            EmailType::LeadNotification,
            EmailType::Cancellation,
            EmailType::Dunning,
            EmailType::QuotaWarning,
            EmailType::WeeklyDigest => $this->ownersOf($this->requireTenant($type, $tenant)),

            EmailType::EnterpriseInquiry => $this->adminInquiryNotifiable(),

            EmailType::TeamInvite,
            EmailType::PasswordReset => throw new LogicException(
                "EmailType::{$type->name} recipients are resolved by the caller (raw email / requesting User), not by RecipientResolver."
            ),
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function ownersOf(Tenant $tenant): Collection
    {
        $userIds = UserRole::query()
            ->forTenant($tenant)
            ->where('role', Role::Owner)
            ->pluck('user_id');

        return User::query()->whereIn('id', $userIds)->get();
    }

    /**
     * @return Collection<int, AnonymousNotifiable>
     */
    private function adminInquiryNotifiable(): Collection
    {
        $notifiable = (new AnonymousNotifiable)->route(
            'mail',
            (string) config('mail.admin_inquiry_address'),
        );

        return new Collection([$notifiable]);
    }

    private function requireTenant(EmailType $type, ?Tenant $tenant): Tenant
    {
        if ($tenant === null) {
            throw new LogicException("EmailType::{$type->name} requires a Tenant.");
        }

        return $tenant;
    }
}
```

- [ ] **Step 4: Run test and verify it passes**

Run:
```bash
php artisan test --filter=RecipientResolverTest
```
Expected: PASS — 8/8.

- [ ] **Step 5: Full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All three: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Email/RecipientResolver.php tests/Unit/Services/Email/RecipientResolverTest.php
git commit -m "feat(email): add RecipientResolver chokepoint

Single service that maps EmailType to recipients. Today returns
owners-only via the user_roles pivot; Phase 21 swaps in a
notification_preferences-aware implementation without touching
notification classes or call sites.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: PaymentReceiptNotification + wiring into `approveAndActivate` (TDD)

**Files:**
- Create: `app/Notifications/Billing/PaymentReceiptNotification.php`
- Modify: `app/Models/Transaction.php`
- Create: `tests/Feature/Email/PaymentReceiptTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Email/PaymentReceiptTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\Role;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Billing\PaymentReceiptNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_is_sent_to_owners_after_successful_approval(): void
    {
        Notification::fake();

        [$tenant, $owner, $tx] = $this->makeAwaitingPaymentTx();

        $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        Notification::assertSentTo($owner, PaymentReceiptNotification::class, function (PaymentReceiptNotification $n) use ($tx): bool {
            return $n->transaction->is($tx);
        });
    }

    public function test_receipt_attaches_the_pdf(): void
    {
        Notification::fake();

        [, $owner, $tx] = $this->makeAwaitingPaymentTx();

        $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        Notification::assertSentTo($owner, PaymentReceiptNotification::class, function (PaymentReceiptNotification $n) use ($owner): bool {
            $mail = $n->toMail($owner);

            return ! empty($mail->attachments);
        });
    }

    public function test_receipt_is_not_sent_when_outer_transaction_rolls_back(): void
    {
        Notification::fake();

        [, $owner, $tx] = $this->makeAwaitingPaymentTx();

        // Wrap in an outer transaction that rolls back. afterCommit must respect
        // the outer transaction and never fire.
        try {
            DB::transaction(function () use ($tx) {
                $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Notification::assertNothingSentTo($owner);
    }

    public function test_receipt_is_not_sent_when_no_owners_exist(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create(['is_active' => true]);
        $tx = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'awaiting_payment',
        ]);

        $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        Notification::assertNothingSent();
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Transaction}
     */
    private function makeAwaitingPaymentTx(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $plan = Plan::factory()->create(['is_active' => true]);
        $tx = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'awaiting_payment',
        ]);

        return [$tenant, $owner, $tx];
    }
}
```

- [ ] **Step 2: Run test and verify it fails**

Run:
```bash
php artisan test --filter=PaymentReceiptTest
```
Expected: FAIL — `App\Notifications\Billing\PaymentReceiptNotification` not found.

- [ ] **Step 3: Implement the notification class**

Create `app/Notifications/Billing/PaymentReceiptNotification.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Transaction;
use App\Services\Billing\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Transaction $transaction) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tx = $this->transaction->loadMissing(['tenant', 'plan']);
        $pdfBytes = app(ReceiptService::class)->generatePdf($tx);

        return (new MailMessage)
            ->subject("Payment receipt — {$tx->transaction_number}")
            ->greeting("Hi {$tx->tenant->name},")
            ->line("We've received your payment for the **{$tx->plan->name}** plan.")
            ->line("**Amount:** Nu. ".number_format((float) $tx->amount, 2))
            ->line("**Reference number:** {$tx->transaction_number}")
            ->line("**Date:** ".$tx->approved_at?->format('M j, Y \a\t g:i A'))
            ->action('View transaction', url("/billing/transactions/{$tx->id}/receipt"))
            ->line('A copy of your receipt is attached.')
            ->line('Thanks for using AbitChat!')
            ->attachData(
                $pdfBytes,
                "receipt-{$tx->transaction_number}.pdf",
                ['mime' => 'application/pdf'],
            );
    }
}
```

- [ ] **Step 4: Wire the dispatch into `Transaction::approveAndActivate`**

Modify `app/Models/Transaction.php` — locate the `approveAndActivate` method and add the after-commit dispatch. The full method should read:

```php
public function approveAndActivate(
    array $allowedFromStatuses,
    ?int $adminId = null,
    ?string $adminNotes = null,
): void {
    DB::transaction(function () use ($allowedFromStatuses, $adminId, $adminNotes) {
        $locked = self::with(['tenant', 'plan'])
            ->whereKey($this->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new TransactionRecordMissing("Transaction {$this->id} not found");
        }
        if (! in_array($locked->status, $allowedFromStatuses, true)) {
            if (in_array($locked->status, ['approved', 'rejected'], true)) {
                throw new TransactionAlreadyProcessed("Transaction {$this->id} is {$locked->status}");
            }
            throw new TransactionStatusNotAllowed("Transaction {$this->id} status {$locked->status} not in allowed list");
        }
        if (! $locked->tenant || ! $locked->plan) {
            throw new TransactionRecordMissing("Tenant or plan missing for transaction {$this->id}");
        }
        if (! $locked->plan->is_active) {
            throw new TransactionPlanInactive("Plan {$locked->plan->id} is not active");
        }

        $locked->update([
            'status' => 'approved',
            'admin_notes' => $adminNotes,
            'approved_by' => $adminId,
            'approved_at' => now(),
        ]);

        $locked->tenant->extendPlan($locked->plan);

        $this->refresh();

        // Email the receipt only after the surrounding transaction commits.
        // afterCommit ensures rollback at any level does not send the email.
        DB::afterCommit(function () use ($locked) {
            $recipients = app(\App\Services\Email\RecipientResolver::class)
                ->recipientsFor(\App\Enums\EmailType::Receipt, $locked->tenant);

            if ($recipients->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send(
                    $recipients,
                    new \App\Notifications\Billing\PaymentReceiptNotification($locked),
                );
            }
        });
    });
}
```

Add the matching `use` statements at the top of `app/Models/Transaction.php` if not already present:
```php
use App\Enums\EmailType;
use App\Notifications\Billing\PaymentReceiptNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Support\Facades\Notification;
```

Once imports exist, rewrite the `DB::afterCommit` closure body using the short names:
```php
DB::afterCommit(function () use ($locked) {
    $recipients = app(RecipientResolver::class)
        ->recipientsFor(EmailType::Receipt, $locked->tenant);

    if ($recipients->isNotEmpty()) {
        Notification::send($recipients, new PaymentReceiptNotification($locked));
    }
});
```

- [ ] **Step 5: Run test and verify it passes**

Run:
```bash
php artisan test --filter=PaymentReceiptTest
```
Expected: PASS — 4/4.

- [ ] **Step 6: Full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All three: PASS. If PHPStan flags anything (likely on the `loadMissing` covariance or attachment array shape), fix the docblock annotations rather than adding a baseline entry — baseline must remain 0.

- [ ] **Step 7: Commit**

```bash
git add app/Notifications/Billing/PaymentReceiptNotification.php app/Models/Transaction.php tests/Feature/Email/PaymentReceiptTest.php
git commit -m "feat(email): send PDF receipt on payment approval

Transaction::approveAndActivate now schedules a receipt notification
via DB::afterCommit so a rollback never sends the email. PDF is
generated via the existing ReceiptService and attached to the
MailMessage. Recipients resolved via RecipientResolver.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Move + refactor `NewLeadNotification` (TDD)

**Files:**
- Delete: `app/Notifications/NewLeadNotification.php`
- Create: `app/Notifications/Leads/NewLeadNotification.php`
- Modify: `app/Services/Leads/LeadService.php`
- Modify: any existing lead notification test file (find via grep below)

- [ ] **Step 1: Find existing lead notification test(s)**

Run:
```bash
grep -rln "NewLeadNotification" tests
```
List the files. Update the import + namespace in each so they reference `App\Notifications\Leads\NewLeadNotification`.

- [ ] **Step 2: Write/extend the failing test**

If an existing lead notification test exists, update its imports + namespace. Then either edit it or create `tests/Feature/Email/NewLeadNotificationTest.php` with the full contents below:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Leads\NewLeadNotification;
use App\Services\Leads\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewLeadNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_notification_routes_via_resolver_to_owners(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'lead@example.com']);

        app(LeadService::class)->notifyNewLead($lead);

        Notification::assertSentTo($owner, NewLeadNotification::class);
    }

    public function test_lead_notification_reply_to_is_lead_email_when_present(): void
    {
        $lead = Lead::factory()->create(['email' => 'lead@example.com']);

        $mail = (new NewLeadNotification($lead))->toMail(new AnonymousNotifiable);

        $this->assertSame(['lead@example.com' => null], $mail->replyTo);
    }

    public function test_lead_notification_reply_to_falls_back_to_support_when_lead_email_null(): void
    {
        $lead = Lead::factory()->create(['email' => null]);

        $mail = (new NewLeadNotification($lead))->toMail(new AnonymousNotifiable);

        $this->assertSame(['support@abit.bt' => null], $mail->replyTo);
    }
}
```

(Notes: `$mail->replyTo` is a public property on `MailMessage` containing `[email => name]` pairs. `LeadService::notifyNewLead` is a private method today — if so, expose it via an existing public entry point in `LeadService` that calls it as part of capture, or temporarily make it `public` to test directly; the simpler path is to drive it through the public lead-capture entry point and assert the side effect.)

- [ ] **Step 3: Run tests and verify they fail**

```bash
php artisan test --filter=NewLeadNotification
```
Expected: FAIL — namespace not found or assertions don't match.

- [ ] **Step 4: Move + refactor the notification class**

Delete `app/Notifications/NewLeadNotification.php` and create `app/Notifications/Leads/NewLeadNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Leads;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $scoreLabel = $this->scoreLabel($lead->score);
        $replyTo = $lead->email ?: 'support@abit.bt';

        $mail = (new MailMessage)
            ->subject("New lead: {$lead->name} ({$scoreLabel})")
            ->replyTo($replyTo)
            ->greeting('New lead captured!')
            ->line('A new lead has been captured from your chatbot.')
            ->line("**Name:** {$lead->name}")
            ->when($lead->email, fn ($m) => $m->line("**Email:** {$lead->email}"))
            ->when($lead->phone, fn ($m) => $m->line("**Phone:** {$lead->phone}"))
            ->when($lead->company, fn ($m) => $m->line("**Company:** {$lead->company}"))
            ->line("**Lead score:** {$lead->score}/100 ({$scoreLabel})")
            ->action('View lead', url("/leads/{$lead->id}"))
            ->line('Follow up soon to maximize conversion.');

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'email' => $this->lead->email,
            'score' => $this->lead->score,
        ];
    }

    private function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Hot',
            $score >= 60 => 'Warm',
            $score >= 40 => 'Moderate',
            default => 'Cold',
        };
    }
}
```

- [ ] **Step 5: Update `LeadService::notifyNewLead` to route through resolver**

Open `app/Services/Leads/LeadService.php`. Find `notifyNewLead` method. Replace the `$user->notify(new NewLeadNotification(...))` block with:

```php
private function notifyNewLead(Lead $lead): void
{
    $recipients = app(\App\Services\Email\RecipientResolver::class)
        ->recipientsFor(\App\Enums\EmailType::LeadNotification, $lead->tenant);

    if ($recipients->isNotEmpty()) {
        \Illuminate\Support\Facades\Notification::send(
            $recipients,
            new \App\Notifications\Leads\NewLeadNotification($lead),
        );
    }
}
```

Add the matching `use` statements at the top:
```php
use App\Enums\EmailType;
use App\Notifications\Leads\NewLeadNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Support\Facades\Notification;
```

Remove any `use App\Notifications\NewLeadNotification;` left behind.

- [ ] **Step 6: Update other call sites that import the old namespace**

Run:
```bash
grep -rln "App\\\\Notifications\\\\NewLeadNotification" app tests
```
Each file found: change to `App\Notifications\Leads\NewLeadNotification`.

- [ ] **Step 7: Run tests and verify they pass**

```bash
php artisan test --filter=NewLeadNotification
```
Expected: PASS for the new and existing tests.

- [ ] **Step 8: Full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All three: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Notifications/Leads/ app/Services/Leads/LeadService.php tests
git rm app/Notifications/NewLeadNotification.php
git commit -m "refactor(email): move NewLeadNotification under Leads/ and route via RecipientResolver

Lead notifications now route through the resolver chokepoint. Reply-to
is the lead's own email when present (falls back to support@abit.bt
for leads with no email).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Move + refactor `EnterpriseInquiryNotification` (TDD)

**Files:**
- Delete: `app/Notifications/EnterpriseInquiryNotification.php`
- Create: `app/Notifications/Admin/EnterpriseInquiryNotification.php`
- Modify: `app/Http/Controllers/Client/EnterpriseInquiryController.php`
- Modify: any existing inquiry notification test (find via grep)

- [ ] **Step 1: Find existing inquiry notification test(s)**

Run:
```bash
grep -rln "EnterpriseInquiryNotification" tests
```

- [ ] **Step 2: Write/extend the failing test**

In the test file (existing or new at `tests/Feature/Email/EnterpriseInquiryNotificationTest.php`), add:
```php
public function test_inquiry_routes_to_configured_admin_address(): void
{
    Notification::fake();
    config(['mail.admin_inquiry_address' => 'enterprise@abit.bt']);

    $payload = [
        'name' => 'Jane Doe',
        'email' => 'jane@acme.test',
        'company' => 'Acme',
        'message' => 'Hi we are interested.',
    ];

    $this->post(route('enterprise.inquiry.store'), $payload)->assertOk();

    Notification::assertSentOnDemand(\App\Notifications\Admin\EnterpriseInquiryNotification::class, function ($n, $channels, $notifiable) {
        return $notifiable->routeNotificationFor('mail') === 'enterprise@abit.bt';
    });
}

public function test_inquiry_reply_to_is_inquirer_email(): void
{
    $inquiry = \App\Models\EnterpriseInquiry::factory()->create(['email' => 'jane@acme.test']);
    $notif = new \App\Notifications\Admin\EnterpriseInquiryNotification($inquiry);

    $mail = $notif->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

    $this->assertSame(['jane@acme.test' => null], $mail->replyTo);
}
```

(Confirm the actual route name from `routes/web.php` and adjust if needed — grep `enterprise.inquiry` to find the right name.)

- [ ] **Step 3: Run tests, verify failure**

```bash
php artisan test --filter=EnterpriseInquiryNotification
```
Expected: FAIL.

- [ ] **Step 4: Move + refactor the notification class**

Read the original file at `app/Notifications/EnterpriseInquiryNotification.php` to capture its current body. Create `app/Notifications/Admin/EnterpriseInquiryNotification.php` with these specific differences:

1. **Top of file:**
   - Replace `namespace App\Notifications;` with `namespace App\Notifications\Admin;`
   - Keep all existing imports and trait usage; the class signature stays identical
2. **Inside `toMail()`:** chain `->replyTo($this->inquiry->email)` immediately after `new MailMessage` so reply-to is set before the subject/greeting calls
3. **Implements / use traits:** unchanged

After writing the new file, delete the old one:
```bash
git rm app/Notifications/EnterpriseInquiryNotification.php
```

Verify the moved class is identical to the original except for namespace and reply-to:
```bash
diff <(grep -v 'namespace\|replyTo' app/Notifications/Admin/EnterpriseInquiryNotification.php) <(echo "ORIGINAL DELETED — compare against git show HEAD:app/Notifications/EnterpriseInquiryNotification.php if needed")
```

- [ ] **Step 5: Update `EnterpriseInquiryController::store`**

Open `app/Http/Controllers/Client/EnterpriseInquiryController.php`. Find the `Notification::route('mail', $adminEmail)->notify(new EnterpriseInquiryNotification($inquiry));` block and replace with:

```php
$recipients = app(\App\Services\Email\RecipientResolver::class)
    ->recipientsFor(\App\Enums\EmailType::EnterpriseInquiry);

\Illuminate\Support\Facades\Notification::send(
    $recipients,
    new \App\Notifications\Admin\EnterpriseInquiryNotification($inquiry),
);
```

Update `use` statements at the top accordingly. Remove the old `Notification::route(...)` and the hardcoded `$adminEmail` lookup.

- [ ] **Step 6: Update any other call sites**

Run:
```bash
grep -rln "App\\\\Notifications\\\\EnterpriseInquiryNotification" app tests
```
Update each.

- [ ] **Step 7: Run tests, verify pass**

```bash
php artisan test --filter=EnterpriseInquiryNotification
```
Expected: PASS.

- [ ] **Step 8: Full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Notifications/Admin/ app/Http/Controllers/Client/EnterpriseInquiryController.php tests
git rm app/Notifications/EnterpriseInquiryNotification.php
git commit -m "refactor(email): move EnterpriseInquiryNotification under Admin/ and route via RecipientResolver

Inquiry email now goes to config('mail.admin_inquiry_address') resolved
by RecipientResolver. Reply-to is the inquirer's email.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Branded `ResetPasswordNotification` (TDD)

**Files:**
- Create: `app/Notifications/Auth/ResetPasswordNotification.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/Email/PasswordResetEmailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Email/PasswordResetEmailTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_dispatches_branded_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'jane@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_reset_email_contains_reset_url_with_token(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test']);
        $notif = new ResetPasswordNotification('test-token-abc123');

        $mail = $notif->toMail($user);

        $rendered = $mail->render();
        $this->assertStringContainsString('test-token-abc123', $rendered);
        $this->assertStringContainsString('reset-password', $rendered);
    }

    public function test_reset_email_uses_abitchat_theme(): void
    {
        $user = User::factory()->create();
        $notif = new ResetPasswordNotification('any-token');

        $rendered = $notif->toMail($user)->render();

        // Header wordmark from the abitchat theme
        $this->assertStringContainsString('AbitChat', $rendered);
        // Footer support address from the customized footer
        $this->assertStringContainsString('support@abit.bt', $rendered);
    }
}
```

- [ ] **Step 2: Run test, verify failure**

```bash
php artisan test --filter=PasswordResetEmailTest
```
Expected: FAIL — `App\Notifications\Auth\ResetPasswordNotification` not found.

- [ ] **Step 3: Implement `ResetPasswordNotification`**

Create `app/Notifications/Auth/ResetPasswordNotification.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $email,
        ], false));

        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your AbitChat password')
            ->greeting('Hi there,')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset password', $url)
            ->line("This password reset link will expire in {$expireMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
```

- [ ] **Step 4: Override `User::sendPasswordResetNotification`**

Open `app/Models/User.php`. Add the method (anywhere in the class body):
```php
public function sendPasswordResetNotification($token): void
{
    $this->notify(new \App\Notifications\Auth\ResetPasswordNotification($token));
}
```

If the file doesn't already import the class, add at the top:
```php
use App\Notifications\Auth\ResetPasswordNotification;
```
and shorten the method body to `$this->notify(new ResetPasswordNotification($token));`.

- [ ] **Step 5: Run tests, verify pass**

```bash
php artisan test --filter=PasswordResetEmailTest
```
Expected: PASS — 3/3.

- [ ] **Step 6: Full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Notifications/Auth/ app/Models/User.php tests/Feature/Email/PasswordResetEmailTest.php
git commit -m "feat(email): branded password reset notification

User::sendPasswordResetNotification now dispatches our themed
ResetPasswordNotification rather than Laravel's default. Header
wordmark, footer support address, and AbitChat green CTA included.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Rendering snapshot tests (TDD)

**Goal:** One small test class that renders each of the four notifications and asserts the brand strings + key content render correctly. Catches accidental template breakage.

**Files:**
- Create: `tests/Feature/Email/EmailRenderingSnapshotTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/Email/EmailRenderingSnapshotTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\Role;
use App\Models\EnterpriseInquiry;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Admin\EnterpriseInquiryNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Billing\PaymentReceiptNotification;
use App\Notifications\Leads\NewLeadNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class EmailRenderingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_receipt_renders_with_brand_and_amount(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Demo Co']);
        $plan = Plan::factory()->create(['name' => 'Starter', 'price' => 999.00]);
        $tx = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'approved',
            'transaction_number' => 'TXN-DEMO',
            'amount' => 999.00,
            'approved_at' => now(),
        ]);
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $html = (new PaymentReceiptNotification($tx))->toMail($owner)->render();

        $this->assertStringContainsString('AbitChat', $html);
        $this->assertStringContainsString('support@abit.bt', $html);
        $this->assertStringContainsString('Nu. 999.00', $html);
        $this->assertStringContainsString('TXN-DEMO', $html);
    }

    public function test_lead_notification_renders_with_score_label(): void
    {
        $lead = Lead::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'score' => 75,
        ]);

        $html = (new NewLeadNotification($lead))->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Warm', $html);
        $this->assertStringContainsString('AbitChat', $html);
    }

    public function test_enterprise_inquiry_renders_with_company(): void
    {
        $inq = EnterpriseInquiry::factory()->create([
            'name' => 'Jane Doe',
            'company' => 'Acme Corp',
            'email' => 'jane@acme.test',
        ]);

        $html = (new EnterpriseInquiryNotification($inq))->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('AbitChat', $html);
    }

    public function test_password_reset_renders_with_token(): void
    {
        $user = User::factory()->create();

        $html = (new ResetPasswordNotification('token-xyz'))->toMail($user)->render();

        $this->assertStringContainsString('token-xyz', $html);
        $this->assertStringContainsString('AbitChat', $html);
        $this->assertStringContainsString('support@abit.bt', $html);
    }
}
```

- [ ] **Step 2: Run tests, verify pass**

```bash
php artisan test --filter=EmailRenderingSnapshotTest
```
Expected: PASS — 4/4. (No new implementation needed; these tests exercise classes already built in earlier tasks.)

- [ ] **Step 3: Full suite + PHPStan + Pint**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Email/EmailRenderingSnapshotTest.php
git commit -m "test(email): render snapshot tests for all 4 notifications

Renders each notification to HTML and asserts the brand strings,
support address, and critical content (amounts, names, tokens) appear.
Catches template regressions.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Hygiene + browser smoke + PR

- [ ] **Step 1: Pint pass 1**

```bash
./vendor/bin/pint --test
```
If anything flagged, run `./vendor/bin/pint`, then `php artisan test` to confirm no regressions, then commit:
```bash
git commit -am "style(pint): apply auto-fixes"
```

- [ ] **Step 2: `/simplify` pass 1**

Invoke the `/simplify` slash command on the touched files. Apply real fixes; skip stylistic noise with a one-line reason.

- [ ] **Step 3: Pint pass 2**

```bash
./vendor/bin/pint --test
```
If flagged, fix + commit.

- [ ] **Step 4: `/simplify` pass 2**

Final simplify pass on anything new the first pass introduced.

- [ ] **Step 5: Browser smoke checklist (with Mailpit running)**

Open http://localhost:8025 in one tab. Then:

- [ ] Log in as `owner@demo.example` / `password`. Trigger a payment approval — easiest is via tinker: `app(\App\Services\Payment\DkBank\DkBankQrService::class)` ... actually simpler: create a pending transaction in tinker then call `approveAndActivate`. Confirm Mailpit shows the receipt email with PDF attachment.
- [ ] Capture a lead via the widget (`http://127.0.0.1:8001/widget/test.html`). Confirm Mailpit shows the lead notification.
- [ ] Submit an enterprise inquiry from the marketing page. Confirm Mailpit shows the inquiry email.
- [ ] Hit `/forgot-password` with `owner@demo.example`. Confirm Mailpit shows the branded reset email.

Eyeball the layout — header wordmark visible, footer shows `support@abit.bt`, CTA buttons green.

- [ ] **Step 6: Full suite + PHPStan one more time**

```bash
php artisan test
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```
All: PASS.

- [ ] **Step 7: Open PR**

Title (under 70 chars): `feat(email): foundation + 4 transactional notifications`

Body:
```markdown
## Summary
- Adds Resend (prod) + Mailpit (dev) email delivery foundation
- Adds `EmailType` enum + `RecipientResolver` chokepoint so Phase 17/19/21 emails drop in without refactor
- Ships 4 user-facing emails: payment receipt (with PDF attached), lead notification, enterprise inquiry, password reset
- Theme: branded Laravel markdown notifications, green `#22c55e` CTAs, AbitChat header + `support@abit.bt` footer

## Deploy steps
1. `composer install` (pulls in `resend/resend-laravel`)
2. Verify `abit.bt` in Resend dashboard (DKIM TXT records + SPF)
3. Set prod env: `MAIL_MAILER=resend`, `RESEND_KEY=...`, `MAIL_FROM_ADDRESS=noreply@abit.bt`, `MAIL_FROM_NAME=AbitChat`, `ADMIN_INQUIRY_EMAIL=support@abit.bt`
4. Confirm queue worker running (`php artisan queue:work --tries=3`)
5. Smoke from tinker: `\Mail::raw('smoke', fn ($m) => $m->to('sam@abit.bt')->subject('Smoke'));` — confirm receipt
6. Rollback path: set `MAIL_MAILER=log` — no killswitch required, the only behavior change is "log → real provider"

## ⚠️ Behavior changes
- `Transaction::approveAndActivate()` now dispatches a `PaymentReceiptNotification` via `DB::afterCommit`. Tenants with zero owners get no email (warning logged); tests assume at least one owner per tenant.
- `NewLeadNotification` moved from `App\Notifications\` to `App\Notifications\Leads\`. Update any external integration or import.
- `EnterpriseInquiryNotification` moved from `App\Notifications\` to `App\Notifications\Admin\`. Recipient now resolved via `config('mail.admin_inquiry_address')` rather than hardcoded.
- Password reset emails are now branded and use the AbitChat theme. Token format and expiry unchanged.

## Test plan
- [ ] `php artisan test` full suite green
- [ ] `./vendor/bin/phpstan analyse` baseline 0
- [ ] `./vendor/bin/pint --test` clean
- [ ] Browser smoke: receipt with PDF visible in Mailpit
- [ ] Browser smoke: lead notification visible in Mailpit, reply-to = lead email
- [ ] Browser smoke: enterprise inquiry visible in Mailpit at admin address
- [ ] Browser smoke: password reset visible in Mailpit, branded layout

## Architecture notes
- `RecipientResolver` is the Phase 21 swap point: when notification_preferences ships, only that class changes.
- All EmailType-routed dispatches go through `RecipientResolver::recipientsFor(...)` — greppable invariant: every `Notification::send(` should pair with a `recipientsFor(`. (Password reset is the deliberate exception — it uses `$user->notify($notification)` because the recipient is always the requesting user and the path is single-user.)
- Receipt PDF size is ~880 KB today (dompdf font embedding); 40 MB Resend limit gives ~46× headroom.

## Spec + plan
- Spec: `docs/superpowers/specs/2026-05-21-email-integration-design.md`
- Plan: `docs/superpowers/plans/2026-05-21-email-integration.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

```bash
gh pr create --title "feat(email): foundation + 4 transactional notifications" --body "..."
```

---

## Out of scope (do not implement in this PR)

- The 5 deferred emails (team invite, cancellation, dunning, quota warning, weekly digest) — their feature phases own them.
- `notification_preferences` table + toggle UI — Phase 21.
- Per-tenant sender branding.
- Internationalization (Dzongkha).
- DKIM/SPF DNS automation (manual ops step).
- Resend webhook handler.
- Open/click tracking.
- `List-Unsubscribe` header.
