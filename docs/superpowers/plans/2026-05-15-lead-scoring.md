# Plan C — Lead Scoring Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the two parallel scoring services (`LeadService::calculateScore` and `LeadScoringService::updateLeadScore`) into one canonical `App\Services\Leads\LeadScoring` module. Same Lead, same scoring math, regardless of entry point (widget chat or widget lead-form submission).

**Architecture:** New `LeadScoring` service owns the full signal set (8 contact/engagement + 6 intent + 1 negative), all weight tables, all keyword dictionaries, and temperature thresholds. `LeadService` keeps capture/orchestration but delegates scoring. `Widget/LeadController` swaps its DI from `LeadScoringService` to `LeadScoring`. Old `LeadScoringService.php` is deleted.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit. Pure refactor — no migrations, no UI, no routes.

**Spec:** `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md` (Cluster C).

---

## Background — what the unified `LeadScoring` looks like

Per the locked decisions table in the master spec:

**Signal set (LeadScoringService baseline + LeadService additions; `high_engagement` dropped):**

| Signal | Weight | Source / trigger |
|---|---|---|
| `provided_email` | +20 | `$lead->email` non-empty |
| `provided_phone` | +15 | `$lead->phone` non-empty |
| `provided_name` | +10 | `$lead->name` non-empty |
| `provided_company` | +10 | `$lead->company` non-empty (NEW from LeadService) |
| `message_sent` | +2 each | one point each user message (NEW from LeadService; cumulative) |
| `long_conversation` | +5 | conversation has ≥ 5 user messages (NEW from LeadService) |
| `return_visitor` | +10 | lead has ≥ 2 conversations (NEW from LeadService; queried via `Conversation::where('lead_id', $lead->id)->count()`) |
| `asked_about_pricing` | +25 | pricing keywords matched (LeadScoringService weight wins) |
| `asked_about_demo` | +30 | demo keywords matched (LeadScoringService weight wins) |
| `mentioned_timeline` | +25 | timeline keywords matched |
| `mentioned_competitor` | +20 | competitor keywords matched |
| `asked_about_contact` | +10 | contact keywords matched (NEW dictionary from LeadService, implementer-chosen weight — see "implementer-chosen weights" callout below) |
| `asked_about_purchase` | +15 | purchase keywords matched (NEW dictionary from LeadService, implementer-chosen weight) |
| `negative_sentiment` | −10 | negative keywords matched |

`high_engagement` (binary +15 when >5 messages) is **dropped** — it double-counts with `message_sent` + `long_conversation`. `multiple_sessions` is **dropped** — it existed as dead code in the old `LeadScoringService` signals array (never triggered in `calculateScore`); `return_visitor` is its canonical replacement.

**Keyword dictionaries (union of both old services):**
- `pricing`: price, pricing, cost, quote, budget, affordable, expensive, cheap, how much, rate, fee, charge, estimate
- `demo`: demo, demonstration, trial, try, test, sample, preview, see it, show me, walkthrough, presentation
- `timeline`: urgent, asap, immediately, today, this week, deadline, soon, quickly, right away, now
- `competitor`: competitor, alternative, compared to, versus, vs, switch from, migrate, currently using
- `negative`: frustrated, angry, disappointed, terrible, awful, hate, worst, useless, waste, scam
- `contact` (NEW): contact, call me, reach out, talk to someone, speak with
- `purchase` (NEW): buy, purchase, subscribe, sign up, get started

Each dictionary fires its signal at most once per call (existing `containsAny` semantics). The `pricing` dictionary unions LeadService's `'estimate'` with LeadScoringService's existing list.

**Implementer-chosen weights for new dictionaries.** The spec lists `contact` and `purchase` in the "Union" of keyword dictionaries but does not specify their weights. This plan assigns:
- `asked_about_contact = +10` — light signal (asking to be contacted ≈ comparable to `provided_name`).
- `asked_about_purchase = +15` — strong intent without commitment (between contact and pricing/demo).

These are flagged in the PR description so a reviewer can object if the values feel wrong. They were chosen to slot into the existing weight scale without dominating short-conversation scoring.

**Public interface (locked):**
- `score(Lead $lead, ?Conversation $conversation = null): int` — returns 0–100 clamped score. If `$conversation` is null, falls back to `$lead->conversation` relation.
- `temperature(int $score): string` — returns `'hot'` (≥ 61), `'warm'` (≥ 31), or `'cold'` (otherwise).

Naming change from the old service: `calculateScore` → `score`, `getTemperature` → `temperature`. The old `updateLeadScore` helper is dropped — callers compute the score and persist it directly (avoids hiding a `save()` inside the scoring service, which complicates testing and transactional control).

---

## File Structure

**New files:**
- `app/Services/Leads/LeadScoring.php` — the unified service
- `tests/Unit/Services/Leads/LeadScoringTest.php` — comprehensive unit tests (replaces the old `LeadScoringServiceTest`)

**Modified files:**
- `app/Services/Leads/LeadService.php`
  - `createLead` and `updateLead` delegate to `LeadScoring::score()`
  - Delete private `calculateInitialScore`, `calculateScore`, `scoreHighIntentKeywords` methods
  - Delete `SCORE_WEIGHTS` and `HIGH_INTENT_KEYWORDS` constants
  - `getStats` query opportunistically converted from `Lead::where('tenant_id', $tenant->id)` to `Lead::forTenant($tenant)` — drops 1 baseline entry
- `app/Http/Controllers/Api/V1/Widget/LeadController.php`
  - Constructor injects `LeadScoring` instead of `LeadScoringService`
  - Both calls to `$this->scoringService->updateLeadScore($lead)` replaced with `$lead->score = $scoring->score($lead, $conversation); $lead->save();`
  - `Tenant::where('api_key', ...)` queries already in there are pre-existing — not in scope to convert (no `forTenant` semantics for api_key lookup)
  - `Lead::where('tenant_id', ...)` query for existing-lead lookup opportunistically converted to `Lead::forTenant($tenant)` — drops 2 baseline entries

**Deleted files:**
- `app/Services/Leads/LeadScoringService.php`
- `tests/Unit/Services/Leads/LeadScoringServiceTest.php` (replaced by `LeadScoringTest.php`)

**Baseline shrinkage (Cluster A → C handoff):**
- `phpstan-baseline.neon` — remove 3 baseline entries (47 → 44 violations / 25 → 22 blocks):
  - `app/Services/Leads/LeadService.php` (count: 1, raw `tenant_id` in `getStats`)
  - `app/Http/Controllers/Api/V1/Widget/LeadController.php` (count: 2, raw `tenant_id` in existing-lead lookup paths)
- `reportUnmatchedIgnoredErrors: true` in `phpstan.neon` will fail the build if these entries stay after the rewrites — must be removed in the same task.

**Out of scope:**
- `app/Http/Controllers/Client/LeadController.php` (the dashboard controller) is NOT touched. Its 2 baseline entries remain.
- `app/Services/Analytics/AnalyticsService.php` — 12 baseline entries. Out of scope; cleared in Cluster E.
- Spec out-of-scope items: R3 fresh-from-product signal redesign; sentiment analysis beyond keyword matching; LLM-based intent detection; multi-language keyword sets.

---

## Task 0 — Verifications (no code; probe reality before any rewrites)

**Files:** none modified.

- [ ] **Step 1: Verify `LeadScoringService.php` still has the structure assumed by the spec**

Run:
```bash
sed -n '11,30p;68,72p;148,158p;160,170p' app/Services/Leads/LeadScoringService.php
```

Expected (modulo whitespace):
- Lines 11–30: class header + `$signals` array with `provided_email => 20`, `provided_phone => 15`, `provided_name => 10`, `asked_about_pricing => 25`, `asked_about_demo => 30`, etc.
- Lines 68–72: `public function calculateScore(Lead $lead): int` declaration
- Lines 148–158: `public function getTemperature(int $score): string` returning hot/warm/cold per thresholds 61 / 31
- Lines 160–170: `public function updateLeadScore(Lead $lead): Lead` that calls calculateScore + saves

If the file has been refactored, STOP and align the plan with reality.

- [ ] **Step 2: Verify `LeadService.php` still has the legacy scoring guts**

Run:
```bash
sed -n '20,40p;162,189p;220,247p' app/Services/Leads/LeadService.php
```

Expected:
- Lines 20–40: `SCORE_WEIGHTS` and `HIGH_INTENT_KEYWORDS` class constants with the values referenced in the signal-set table above.
- Lines 162–189: `private function calculateInitialScore(Conversation, array): int`.
- Lines 220–247: `private function scoreHighIntentKeywords(Conversation): int`.

If structure has shifted, STOP.

- [ ] **Step 3: Verify `Widget/LeadController.php` still injects `LeadScoringService`**

Run:
```bash
sed -n '11,21p;73,80p;100,112p' app/Http/Controllers/Api/V1/Widget/LeadController.php
```

Expected:
- Line 11: `use App\Services\Leads\LeadScoringService;`
- Lines 18–20: constructor `public function __construct(private LeadScoringService $scoringService) {}`
- Lines 73–80: `$this->scoringService->updateLeadScore($existingLead);` inside the "existing lead" branch
- Lines 100–112: `$this->scoringService->updateLeadScore($lead);` after new-lead create

If `LeadScoringService` has already been swapped out somewhere, STOP — the plan assumes it's still in place.

- [ ] **Step 4: Verify the 3 PHPStan baseline entries this PR will retire**

Run:
```bash
grep -B1 -A4 "tenancy.rawTenantId" phpstan-baseline.neon | grep -E "count:|path:" | grep -E "LeadService|Widget/LeadController"
```

Expected:
```
			count: 2
			path: app/Http/Controllers/Api/V1/Widget/LeadController.php
			count: 1
			path: app/Services/Leads/LeadService.php
```

If counts/paths differ, STOP. Confirm `reportUnmatchedIgnoredErrors: true` is still set:
```bash
grep -n "reportUnmatchedIgnoredErrors" phpstan.neon
```

Expected: a line containing `reportUnmatchedIgnoredErrors: true`.

- [ ] **Step 5: Confirm test suite + PHPStan are green at HEAD**

Run in parallel:
```bash
php artisan test 2>&1 | tail -3
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -3
```

Expected: full suite passes; PHPStan reports `[OK] No errors`.

- [ ] **Step 6: Verify `Lead::make() + save()` fires the same events as `Lead::create()`**

Task 2 switches `createLead` from `Lead::create([...])` to `Lead::make([...])` then `->score = ...; ->metadata = ...; ->save();`. The semantic must be: both `creating` and `created` events fire on `save()` exactly once, the same way `Lead::create` would. Critical because `BelongsToTenant`'s boot hook listens on `creating` and `BustsTenantUsageCache` listens on `created`.

Run:
```bash
php artisan tinker --execute="\$t = \\App\\Models\\Tenant::first(); \\Illuminate\\Support\\Facades\\Cache::flush(); \$beforeCache = \\Illuminate\\Support\\Facades\\Cache::get(\"tenant:{\$t->id}:usage:leads\"); \$lead = \\App\\Models\\Lead::make(['tenant_id'=>\$t->id,'status'=>'new','source'=>'widget','score'=>0]); \$lead->save(); \$afterCache = \\Illuminate\\Support\\Facades\\Cache::get(\"tenant:{\$t->id}:usage:leads\"); echo json_encode(['lead_id'=>\$lead->id,'exists'=>\$lead->exists,'tenant_id'=>\$lead->tenant_id,'cache_before'=>\$beforeCache,'cache_after'=>\$afterCache]); \\App\\Models\\Lead::find(\$lead->id)?->delete();"
```

Expected:
- `lead_id` is non-null (save() succeeded)
- `exists` is `true`
- `tenant_id` matches the tenant's id (boot hook would auto-stamp if missing; here it's explicit)
- `cache_after` differs from `cache_before` (BustsTenantUsageCache fired and invalidated the count cache)

If `cache_after === cache_before`, the `created` event isn't firing on the make-then-save sequence — STOP and adjust Task 2 to use `Lead::create([...])` for the create path instead (drop the rescore-before-save optimization; create with score=0, then update with the computed score in a follow-up call).

If `tenant_id` is null, the `creating` boot hook didn't auto-stamp — but since we set it explicitly in the make array, this shouldn't matter. If it IS null in the output, there's a deeper bug; STOP and investigate.

- [ ] **Step 7: Confirm callers of the legacy services**

Run:
```bash
grep -rn "LeadScoringService\|LeadService" app/ tests/ routes/ 2>/dev/null
```

Expected callers (the only ones — anything else is a surprise):
- `app/Http/Controllers/Api/V1/Widget/ChatController.php` injects `LeadService` (calls `extractContactInfo`, `captureFromConversation` — NOT scoring methods directly; OK to leave alone)
- `app/Http/Controllers/Api/V1/Widget/LeadController.php` injects `LeadScoringService`
- `app/Http/Controllers/Client/LeadController.php` injects `LeadService` (dashboard CRUD; uses `adjustScore`, `getStats` — NOT scoring methods; OK to leave alone)
- `tests/Feature/WidgetLeadCaptureTest.php` resolves `LeadService` from the container (uses `captureFromConversation` directly; OK to leave alone)
- `tests/Unit/Services/Leads/LeadScoringServiceTest.php` instantiates `LeadScoringService` directly (will be replaced)

If a caller exists that we haven't accounted for (e.g., a console command, another controller), STOP and update the plan.

- [ ] **Step 8: Decide whether to proceed**

If every verification matched expectations, proceed to Task 1.

If any verification surfaced an unexpected result, **stop and discuss with the user before proceeding**. Do not modify this plan file mid-execution.

---

## Task 1 — `LeadScoring` service + comprehensive unit test

**Files:**
- Create: `app/Services/Leads/LeadScoring.php`
- Create: `tests/Unit/Services/Leads/LeadScoringTest.php`

### Step 1: Write the failing test

Create `tests/Unit/Services/Leads/LeadScoringTest.php` verbatim:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Leads\LeadScoring;
use Tests\TestCase;

class LeadScoringTest extends TestCase
{
    private LeadScoring $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeadScoring;
        $this->createTenantWithUser();
    }

    private function makeLead(array $attrs = [], ?Conversation $conversation = null): Lead
    {
        return Lead::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation?->id,
            'status' => 'new',
            'source' => 'widget',
        ], $attrs));
    }

    private function makeConversationWithMessages(array $userMessages): Conversation
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'test-session-'.uniqid(),
            'status' => 'active',
        ]);

        foreach ($userMessages as $content) {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $content,
            ]);
        }

        return $conversation;
    }

    /* ---------- Contact-info signals ---------- */

    public function test_score_is_zero_for_lead_with_no_signals(): void
    {
        $lead = $this->makeLead();

        $this->assertSame(0, $this->service->score($lead));
    }

    public function test_email_adds_twenty_points(): void
    {
        $lead = $this->makeLead(['email' => 'visitor@example.com']);

        $this->assertSame(20, $this->service->score($lead));
    }

    public function test_phone_adds_fifteen_points(): void
    {
        $lead = $this->makeLead(['phone' => '+1234567890']);

        $this->assertSame(15, $this->service->score($lead));
    }

    public function test_name_adds_ten_points(): void
    {
        $lead = $this->makeLead(['name' => 'Visitor Name']);

        $this->assertSame(10, $this->service->score($lead));
    }

    public function test_company_adds_ten_points(): void
    {
        $lead = $this->makeLead(['company' => 'Acme Inc']);

        $this->assertSame(10, $this->service->score($lead));
    }

    public function test_full_contact_info_combines_signals(): void
    {
        $lead = $this->makeLead([
            'name' => 'Visitor Name',
            'email' => 'visitor@example.com',
            'phone' => '+1234567890',
            'company' => 'Acme',
        ]);

        // 20 + 15 + 10 + 10 = 55
        $this->assertSame(55, $this->service->score($lead));
    }

    /* ---------- Intent signals ---------- */

    public function test_pricing_keyword_adds_twenty_five_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing for your service?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 25 (pricing) + 2 (one user message) = 27
        $this->assertSame(27, $this->service->score($lead, $conversation));
    }

    public function test_demo_keyword_adds_thirty_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Can I book a demo?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 30 (demo) + 2 (one user message) = 32
        $this->assertSame(32, $this->service->score($lead, $conversation));
    }

    public function test_timeline_keyword_adds_twenty_five_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'I need this asap, please.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 25 (timeline) + 2 = 27
        $this->assertSame(27, $this->service->score($lead, $conversation));
    }

    public function test_competitor_keyword_adds_twenty_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'How do you compare versus your competitor?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 20 (competitor) + 2 = 22
        $this->assertSame(22, $this->service->score($lead, $conversation));
    }

    public function test_contact_keyword_adds_ten_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Please reach out to me directly.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 10 (contact) + 2 = 12
        $this->assertSame(12, $this->service->score($lead, $conversation));
    }

    public function test_purchase_keyword_adds_fifteen_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Where do I sign up to buy this?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 'sign up' AND 'buy' both in purchase dictionary, but signal fires once.
        // 15 (purchase) + 2 = 17
        $this->assertSame(17, $this->service->score($lead, $conversation));
    }

    public function test_negative_sentiment_subtracts_ten_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'This is terrible service.',
        ]);
        $lead = $this->makeLead(['email' => 'visitor@example.com'], $conversation);

        // 20 (email) + 2 (one message) - 10 (negative) = 12
        $this->assertSame(12, $this->service->score($lead, $conversation));
    }

    /* ---------- Engagement signals ---------- */

    public function test_each_user_message_adds_two_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 3 messages × 2 = 6, no other signals fire (messages are too short, no keywords)
        $this->assertSame(6, $this->service->score($lead, $conversation));
    }

    public function test_long_conversation_kicks_in_at_five_messages(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three', 'four', 'five',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 5 × 2 = 10 + 5 (long_conversation) = 15
        $this->assertSame(15, $this->service->score($lead, $conversation));
    }

    public function test_long_conversation_does_not_kick_in_below_five_messages(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three', 'four',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 4 × 2 = 8, no long_conversation bonus
        $this->assertSame(8, $this->service->score($lead, $conversation));
    }

    public function test_return_visitor_adds_ten_when_lead_has_multiple_conversations(): void
    {
        $firstConvo = $this->makeConversationWithMessages(['hi']);
        $lead = $this->makeLead([], $firstConvo);

        // Second conversation for the same lead.
        $secondConvo = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'second-'.uniqid(),
            'status' => 'active',
            'lead_id' => $lead->id,
        ]);

        // Also link first conversation back to lead (post-capture linkage).
        $firstConvo->update(['lead_id' => $lead->id]);

        // Scoring against the second conversation: 0 messages × 2 = 0, plus
        // return_visitor (2 linked conversations) = 10.
        $this->assertSame(10, $this->service->score($lead, $secondConvo));
    }

    public function test_return_visitor_does_not_fire_for_single_conversation(): void
    {
        $conversation = $this->makeConversationWithMessages(['hi']);
        $lead = $this->makeLead([], $conversation);
        $conversation->update(['lead_id' => $lead->id]);

        // 1 × 2 = 2, no return_visitor
        $this->assertSame(2, $this->service->score($lead, $conversation));
    }

    /* ---------- Conversation-source fallback ---------- */

    public function test_score_falls_back_to_lead_conversation_when_argument_is_null(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // Same result whether $conversation is passed explicitly or omitted.
        $explicit = $this->service->score($lead, $conversation);
        $fallback = $this->service->score($lead);

        $this->assertSame($explicit, $fallback);
    }

    public function test_score_works_when_lead_has_no_conversation_relation(): void
    {
        $lead = $this->makeLead(['email' => 'visitor@example.com']);

        // No conversation passed, none on the lead — should only count contact signals.
        $this->assertSame(20, $this->service->score($lead));
    }

    /* ---------- Cross-cutting behaviour ---------- */

    public function test_only_user_messages_contribute(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'assistant-only',
            'status' => 'active',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Our pricing starts at $29/month with a 14-day trial.',
        ]);

        $lead = $this->makeLead([], $conversation);

        $this->assertSame(0, $this->service->score($lead, $conversation));
    }

    public function test_keyword_matching_is_case_insensitive(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'WHAT IS THE PRICING?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 25 (pricing) + 2 = 27
        $this->assertSame(27, $this->service->score($lead, $conversation));
    }

    public function test_score_is_clamped_to_zero_minimum(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Your service is awful and I hate it.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 2 (message_sent) - 10 (negative) = -8 → clamped to 0
        $this->assertSame(0, $this->service->score($lead, $conversation));
    }

    public function test_score_is_clamped_to_one_hundred_maximum(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing? Can I book a demo? I need this asap. '.
            'How does it compare versus the competitor? Can you reach out? '.
            'I want to buy this now.',
            'one', 'two', 'three', 'four', 'five', 'six',
        ]);
        $lead = $this->makeLead([
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'phone' => '+1234567890',
            'company' => 'Acme',
        ], $conversation);

        // Total well over 100; should clamp.
        $this->assertSame(100, $this->service->score($lead, $conversation));
    }

    /* ---------- Temperature ---------- */

    public function test_temperature_thresholds(): void
    {
        $this->assertSame('cold', $this->service->temperature(0));
        $this->assertSame('cold', $this->service->temperature(30));
        $this->assertSame('warm', $this->service->temperature(31));
        $this->assertSame('warm', $this->service->temperature(60));
        $this->assertSame('hot', $this->service->temperature(61));
        $this->assertSame('hot', $this->service->temperature(100));
    }
}
```

### Step 2: Run the test to verify it fails

```bash
php artisan test --filter=LeadScoringTest 2>&1 | tail -10
```

Expected: FAIL — `Class "App\Services\Leads\LeadScoring" not found`.

### Step 3: Implement the service

Create `app/Services/Leads/LeadScoring.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Canonical lead-scoring service.
 *
 * Single source of truth for the signal set, weight table, keyword
 * dictionaries, and temperature thresholds used everywhere a Lead's
 * score is computed (widget chat capture, widget lead-form submission,
 * future score-recalc paths).
 *
 * Public surface is intentionally small:
 *   - score(Lead, ?Conversation): int   — 0–100 clamped
 *   - temperature(int): string          — hot | warm | cold
 *
 * Persistence is the caller's job. The service does not save the Lead.
 */
class LeadScoring
{
    /**
     * Scoring signals and their point values.
     *
     * @var array<string, int>
     */
    private array $weights = [
        'provided_email' => 20,
        'provided_phone' => 15,
        'provided_name' => 10,
        'provided_company' => 10,
        'message_sent' => 2,
        'long_conversation' => 5,
        'return_visitor' => 10,
        'asked_about_pricing' => 25,
        'asked_about_demo' => 30,
        'mentioned_timeline' => 25,
        'mentioned_competitor' => 20,
        'asked_about_contact' => 10,
        'asked_about_purchase' => 15,
        'negative_sentiment' => -10,
    ];

    /**
     * Keyword dictionaries. Each fires its signal at most once per call.
     *
     * @var array<string, array<int, string>>
     */
    private array $dictionaries = [
        'pricing' => [
            'price', 'pricing', 'cost', 'quote', 'budget', 'affordable',
            'expensive', 'cheap', 'how much', 'rate', 'fee', 'charge',
            'estimate',
        ],
        'demo' => [
            'demo', 'demonstration', 'trial', 'try', 'test', 'sample',
            'preview', 'see it', 'show me', 'walkthrough', 'presentation',
        ],
        'timeline' => [
            'urgent', 'asap', 'immediately', 'today', 'this week',
            'deadline', 'soon', 'quickly', 'right away', 'now',
        ],
        'competitor' => [
            'competitor', 'alternative', 'compared to', 'versus',
            'vs', 'switch from', 'migrate', 'currently using',
        ],
        'negative' => [
            'frustrated', 'angry', 'disappointed', 'terrible', 'awful',
            'hate', 'worst', 'useless', 'waste', 'scam',
        ],
        'contact' => [
            'contact', 'call me', 'reach out', 'talk to someone',
            'speak with',
        ],
        'purchase' => [
            'buy', 'purchase', 'subscribe', 'sign up', 'get started',
        ],
    ];

    /**
     * Map from keyword-dictionary name to the signal name it fires.
     *
     * @var array<string, string>
     */
    private array $dictionaryToSignal = [
        'pricing' => 'asked_about_pricing',
        'demo' => 'asked_about_demo',
        'timeline' => 'mentioned_timeline',
        'competitor' => 'mentioned_competitor',
        'negative' => 'negative_sentiment',
        'contact' => 'asked_about_contact',
        'purchase' => 'asked_about_purchase',
    ];

    public function score(Lead $lead, ?Conversation $conversation = null): int
    {
        $conversation ??= $lead->conversation;

        $score = 0;
        $fired = [];

        // Contact-info signals
        if (! empty($lead->email)) {
            $score += $this->weights['provided_email'];
            $fired[] = 'provided_email';
        }
        if (! empty($lead->phone)) {
            $score += $this->weights['provided_phone'];
            $fired[] = 'provided_phone';
        }
        if (! empty($lead->name)) {
            $score += $this->weights['provided_name'];
            $fired[] = 'provided_name';
        }
        if (! empty($lead->company)) {
            $score += $this->weights['provided_company'];
            $fired[] = 'provided_company';
        }

        // Return-visitor signal (cross-conversation).
        if ($lead->exists && Conversation::where('lead_id', $lead->id)->count() >= 2) {
            $score += $this->weights['return_visitor'];
            $fired[] = 'return_visitor';
        }

        // Conversation-driven signals.
        if ($conversation !== null) {
            $messages = $conversation->messages()->where('role', 'user')->get();
            $messageCount = $messages->count();

            if ($messageCount > 0) {
                $score += $messageCount * $this->weights['message_sent'];
                $fired[] = 'message_sent';
            }

            if ($messageCount >= 5) {
                $score += $this->weights['long_conversation'];
                $fired[] = 'long_conversation';
            }

            $allContentLower = strtolower($messages->pluck('content')->implode(' '));

            foreach ($this->dictionaries as $name => $keywords) {
                if ($this->containsAny($allContentLower, $keywords)) {
                    $signal = $this->dictionaryToSignal[$name];
                    $score += $this->weights[$signal];
                    $fired[] = $signal;
                }
            }
        }

        $score = max(0, min(100, $score));

        Log::debug('[LeadScoring] (NO $) Score calculated', [
            'lead_id' => $lead->id,
            'score' => $score,
            'signals' => $fired,
        ]);

        return $score;
    }

    public function temperature(int $score): string
    {
        if ($score >= 61) {
            return 'hot';
        }

        if ($score >= 31) {
            return 'warm';
        }

        return 'cold';
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
```

### Step 4: Run the test to verify it passes

```bash
php artisan test --filter=LeadScoringTest 2>&1 | tail -10
```

Expected: PASS — all tests green (~21 tests).

### Step 5: Run the full test suite to confirm no regression

```bash
php artisan test 2>&1 | tail -5
```

Expected: pre-existing `LeadScoringServiceTest` still passes (we haven't deleted it yet); everything else still green. Total roughly: previous baseline + 21 new tests.

### Step 6: Pint clean on touched files

```bash
./vendor/bin/pint --test app/Services/Leads/LeadScoring.php tests/Unit/Services/Leads/LeadScoringTest.php 2>&1 | tail -3
```

Apply if needed.

### Step 7: Commit

```bash
git add app/Services/Leads/LeadScoring.php tests/Unit/Services/Leads/LeadScoringTest.php
git commit -m "$(cat <<'EOF'
feat(leads): add canonical LeadScoring service with merged signal set

Single source of truth for lead-scoring math. Merges the two parallel
services (LeadService::calculateScore + LeadScoringService::calculateScore)
into one signal set + weight table + keyword dictionary set.

Public interface: score(Lead, ?Conversation): int and temperature(int): string.
Persistence is the caller's responsibility.

Signal additions from LeadService: provided_company, message_sent,
long_conversation, return_visitor. Dropped: high_engagement (double-counts
with message_sent + long_conversation) and the dead-code multiple_sessions
entry. New keyword dictionaries: contact, purchase.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster C)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 — Wire `LeadService` to delegate to `LeadScoring`; delete legacy scoring guts

**Files:**
- Modify: `app/Services/Leads/LeadService.php`

### Step 1: Rewrite `LeadService.php`

Read the current file first (it's ~380 lines). Then replace the entire body with the version below — this preserves `captureFromConversation`, `extractContactInfo`, `adjustScore`, `getStats`, `notifyNewLead`, and the soft-locked transactional capture flow. Removes `SCORE_WEIGHTS`, `HIGH_INTENT_KEYWORDS`, `calculateInitialScore`, `calculateScore`, `scoreHighIntentKeywords`. Adds an injected `LeadScoring` dependency.

The `getStats` query also opportunistically converts `Lead::where('tenant_id', $tenant->id)` to `Lead::forTenant($tenant)` — drops 1 PHPStan baseline entry.

```php
<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadService
{
    public function __construct(private LeadScoring $scoring) {}

    /**
     * Create or update a lead from conversation data. Concurrent first-message
     * requests on the same conversation serialize on the conversation row so
     * exactly one lead is created.
     *
     * @param  array<string, mixed>  $contactInfo
     */
    public function captureFromConversation(Conversation $conversation, array $contactInfo = []): ?Lead
    {
        if (empty($contactInfo['email']) && empty($contactInfo['phone']) && empty($contactInfo['name'])) {
            return null;
        }

        return DB::transaction(function () use ($conversation, $contactInfo) {
            /** @var Conversation $locked */
            $locked = Conversation::with('tenant')
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Tenant $tenant */
            $tenant = $locked->tenant;

            if ($locked->lead_id) {
                $lead = Lead::find($locked->lead_id);
                if ($lead) {
                    return $this->updateLead($lead, $locked, $contactInfo);
                }
            }

            return $this->createLead($tenant, $locked, $contactInfo);
        });
    }

    /**
     * Create a new lead. Builds the model in memory, scores it via
     * LeadScoring (with the captured conversation in context), then saves
     * everything in one shot so the persisted score matches the metadata.
     *
     * @param  array<string, mixed>  $contactInfo
     */
    private function createLead(Tenant $tenant, Conversation $conversation, array $contactInfo): Lead
    {
        $lead = Lead::make([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'name' => $contactInfo['name'] ?? null,
            'email' => $contactInfo['email'] ?? null,
            'phone' => $contactInfo['phone'] ?? null,
            'company' => $contactInfo['company'] ?? null,
            'status' => 'new',
            'source' => 'chatbot',
        ]);

        $score = $this->scoring->score($lead, $conversation);
        $lead->score = $score;
        $lead->metadata = [
            'first_conversation_id' => $conversation->id,
            'captured_at' => now()->toIso8601String(),
            'initial_score' => $score,
        ];
        $lead->save();

        // Link conversation to lead
        $conversation->update(['lead_id' => $lead->id]);

        // Send notification after transaction commits so the worker sees the committed row
        DB::afterCommit(fn () => $this->notifyNewLead($lead));

        Log::info('[Lead] (NO $) New lead captured', [
            'lead_id' => $lead->id,
            'tenant_id' => $tenant->id,
            'score' => $score,
        ]);

        return $lead;
    }

    /**
     * Update existing lead with new info. Scoring goes through the canonical
     * LeadScoring service.
     *
     * @param  array<string, mixed>  $contactInfo
     */
    private function updateLead(Lead $lead, Conversation $conversation, array $contactInfo): Lead
    {
        $updates = [];

        // Update contact info if provided and not already set
        if (! empty($contactInfo['name']) && empty($lead->name)) {
            $updates['name'] = $contactInfo['name'];
        }
        if (! empty($contactInfo['phone']) && empty($lead->phone)) {
            $updates['phone'] = $contactInfo['phone'];
        }
        if (! empty($contactInfo['company']) && empty($lead->company)) {
            $updates['company'] = $contactInfo['company'];
        }

        // Apply the contact-info updates in memory so the rescored value
        // reflects the latest state (LeadScoring reads from $lead attributes).
        if ($updates !== []) {
            $lead->fill($updates);
        }

        $newScore = $this->scoring->score($lead, $conversation);
        if ($newScore !== $lead->score) {
            $updates['score'] = $newScore;
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        // Link conversation to lead if not already linked
        if ($conversation->lead_id !== $lead->id) {
            $conversation->update(['lead_id' => $lead->id]);
        }

        $lead->refresh();

        return $lead;
    }

    /**
     * Extract contact information from message content
     *
     * @return array<string, string|null>
     */
    public function extractContactInfo(string $content): array
    {
        $info = [];

        // Extract email
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $content, $matches)) {
            $info['email'] = strtolower($matches[0]);

            // Try to extract name before email (common pattern: "name email@example.com")
            $emailPos = strpos($content, $matches[0]);
            if ($emailPos > 0) {
                $beforeEmail = trim(substr($content, 0, $emailPos));
                // Get last word(s) before email as potential name (up to 3 words for full names)
                if (preg_match('/([A-Za-z]+(?:\s+[A-Za-z]+){0,2})\s*$/', $beforeEmail, $nameMatch)) {
                    $potentialName = trim($nameMatch[1]);
                    // Validate it looks like a name (not common words)
                    $excludeWords = ['my', 'is', 'am', 'the', 'and', 'or', 'to', 'from', 'at', 'for', 'it', 'its', 'yes', 'no', 'hi', 'hello', 'hey', 'email', 'mail', 'address', 'contact', 'me', 'i', 'im', 'reach', 'send', 'write', 'here', 'this', 'that', 'with', 'can', 'you', 'please', 'thanks', 'thank'];

                    // Check each word in potential name - all must be valid
                    $words = preg_split('/\s+/', strtolower($potentialName));
                    $validWords = array_filter($words, fn ($w) => ! in_array($w, $excludeWords) && strlen($w) >= 2);

                    // Only use as name if we have valid words and they weren't all filtered out
                    if (count($validWords) > 0 && count($validWords) === count($words)) {
                        $info['name'] = ucwords(strtolower($potentialName));
                    }
                }
            }
        }

        // Extract phone (various formats)
        $phonePatterns = [
            '/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', // US format
            '/\+?\d{10,14}/', // International
        ];
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $info['phone'] = preg_replace('/[^\d+]/', '', $matches[0]) ?? '';
                break;
            }
        }

        return $info;
    }

    /**
     * Send notification for new lead
     */
    private function notifyNewLead(Lead $lead): void
    {
        try {
            $tenant = $lead->tenant;
            if (! $tenant) {
                return;
            }

            // Get tenant users to notify
            $users = $tenant->users()->get();

            foreach ($users as $user) {
                $user->notify(new NewLeadNotification($lead));
            }

            Log::info('[Lead] (NO $) Notification queued', [
                'lead_id' => $lead->id,
                'notified_users' => $users->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[Lead] Failed to send notification', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manually update lead score
     */
    public function adjustScore(Lead $lead, int $adjustment, string $reason = ''): Lead
    {
        $newScore = min(100, max(0, $lead->score + $adjustment));

        /** @var array<string, mixed> $metadata */
        $metadata = $lead->metadata ?? [];
        /** @var array<int, array<string, mixed>> $scoreAdjustments */
        $scoreAdjustments = $metadata['score_adjustments'] ?? [];
        $scoreAdjustments[] = [
            'from' => $lead->score,
            'to' => $newScore,
            'adjustment' => $adjustment,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
        $metadata['score_adjustments'] = $scoreAdjustments;

        $lead->update([
            'score' => $newScore,
            'metadata' => $metadata,
        ]);

        $lead->refresh();

        return $lead;
    }

    /**
     * Get lead statistics for a tenant
     *
     * @return array<string, int|float>
     */
    public function getStats(Tenant $tenant): array
    {
        $leads = Lead::forTenant($tenant);

        return [
            'total' => $leads->count(),
            'new' => (clone $leads)->where('status', 'new')->count(),
            'contacted' => (clone $leads)->where('status', 'contacted')->count(),
            'qualified' => (clone $leads)->where('status', 'qualified')->count(),
            'converted' => (clone $leads)->where('status', 'converted')->count(),
            'lost' => (clone $leads)->where('status', 'lost')->count(),
            'average_score' => (float) ((clone $leads)->avg('score') ?? 0),
            'high_quality' => (clone $leads)->where('score', '>=', 70)->count(),
        ];
    }
}
```

What changed vs. the current file:
- New constructor `public function __construct(private LeadScoring $scoring) {}`
- `SCORE_WEIGHTS` and `HIGH_INTENT_KEYWORDS` constants deleted (no longer needed)
- `calculateInitialScore`, `calculateScore`, `scoreHighIntentKeywords` private methods deleted
- `createLead` uses `Lead::make(...)` + `$this->scoring->score($lead, $conversation)` + `$lead->save()` instead of computing score upfront
- `updateLead` applies contact-info fills first (in memory), then rescores via `$this->scoring->score(...)`. This fixes a latent bug in the old code: the old `calculateScore` saw the `provided_company` bonus only via the `contactInfo` parameter, not via `$lead->company`. With the unified scoring reading from the Lead model, applying updates first is the only correct sequencing.
- `getStats` uses `Lead::forTenant($tenant)` instead of `Lead::where('tenant_id', $tenant->id)` — drops 1 baseline entry

### Step 2: Remove the `LeadService.php` baseline entry

Open `phpstan-baseline.neon` and locate:
```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 1
			path: app/Services/Leads/LeadService.php
```

Delete the entire block.

### Step 3: Run PHPStan to confirm baseline shrinkage is consistent

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. If PHPStan complains `Ignored error pattern ... was not matched`, the deletion was incomplete; re-grep the baseline.

### Step 4: Run the suite

```bash
php artisan test 2>&1 | tail -5
```

Expected: PASS — full suite still green. `WidgetLeadCaptureTest` exercises `LeadService::captureFromConversation` end-to-end against a real database, so it actually verifies the delegate-to-`LeadScoring` flow works.

If a test fails because the old `LeadScoringServiceTest` is still in place and the constructor-binding for `LeadScoringService` has changed — it hasn't yet, that test still works against the old class. Task 4 deletes the old service.

If `WidgetLeadCaptureTest::test_existing_lead_blank_fields_can_be_filled_by_widget` fails because the existing-lead score is now different — note: that test doesn't assert score values, only that the contact-info update lands. Should still pass.

### Step 5: Pint clean

```bash
./vendor/bin/pint --test app/Services/Leads/LeadService.php 2>&1 | tail -3
```

Apply if needed.

### Step 6: Commit

```bash
git add app/Services/Leads/LeadService.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(leads): LeadService delegates scoring to canonical LeadScoring

createLead and updateLead build/update the Lead in memory, then call
LeadScoring::score before saving. Removes the duplicate signal set,
weight table, and keyword dictionaries that lived as private constants
+ helpers on LeadService.

updateLead now applies contact-info updates to the in-memory Lead BEFORE
scoring, so signals like provided_company fire on the up-to-date model.
The old code computed contact-info bonuses from the contactInfo array
rather than the Lead, which would diverge once LeadScoring reads
attributes directly.

Semantic shift: old calculateScore was incremental (started from
$lead->score and added bonuses for newly-arrived signals). New
LeadScoring::score is a full re-score from current state every time.
Scores can now go DOWN on an update if a signal stops applying (e.g.,
the conversation's negative-sentiment keyword was edited away). If any
downstream code or analytics assumes score monotonicity within a lead's
lifetime, surface that — Cluster C breaks the assumption.

getStats query opportunistically converted to Lead::forTenant — shrinks
the Cluster A baseline by 1 entry.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster C)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — Swap `Widget/LeadController` DI from `LeadScoringService` to `LeadScoring`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Widget/LeadController.php`

### Step 1: Rewrite `Widget/LeadController.php`

Replace the entire body of `app/Http/Controllers/Api/V1/Widget/LeadController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Leads\LeadScoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LeadController extends Controller
{
    public function __construct(
        private LeadScoring $scoring
    ) {}

    /**
     * Capture lead information from the widget.
     */
    public function capture(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
            'conversation_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
        ]);

        $tenant = Tenant::where('api_key', $request->api_key)->first();

        if (! $tenant) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $conversation = Conversation::where('id', $request->conversation_id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Check for existing lead by email (deduplication)
        $existingLead = null;
        if ($request->email) {
            $existingLead = Lead::forTenant($tenant)
                ->where('email', $request->email)
                ->first();
        }

        if ($existingLead) {
            // Only fill blank fields. Never overwrite values already on record
            // with attacker-controlled input from the widget. custom_fields are
            // intentionally not merged from widget input on duplicate match.
            $updates = [];
            foreach (['name', 'phone', 'company'] as $field) {
                if (empty($existingLead->{$field}) && filled($request->input($field))) {
                    $updates[$field] = $request->input($field);
                }
            }

            if ($updates !== []) {
                $existingLead->update($updates);
            }

            $conversation->update(['lead_id' => $existingLead->id]);

            $existingLead->score = $this->scoring->score($existingLead, $conversation);
            $existingLead->save();

            Log::debug('[Lead] (NO $) Existing lead reattached to conversation', [
                'lead_id' => $existingLead->id,
                'conversation_id' => $conversation->id,
            ]);

            return response()->json([
                'success' => true,
                'lead_id' => $existingLead->id,
            ]);
        }

        // Create new lead (score=0 placeholder, rescored below before save).
        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'company' => $request->company,
            'custom_fields' => $request->custom_fields,
            'status' => 'new',
            'score' => 0,
        ]);

        // Update conversation
        $conversation->update(['lead_id' => $lead->id]);

        // Calculate and persist score
        $lead->score = $this->scoring->score($lead, $conversation);
        $lead->save();

        Log::debug('[Lead] (NO $) New lead captured', [
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'score' => $lead->score,
        ]);

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
        ]);
    }
}
```

What changed:
- `use App\Services\Leads\LeadScoringService;` → `use App\Services\Leads\LeadScoring;`
- Constructor: `private LeadScoringService $scoringService` → `private LeadScoring $scoring`
- Existing-lead lookup: `Lead::where('tenant_id', $tenant->id)->where('email', ...)` → `Lead::forTenant($tenant)->where('email', ...)` — drops 1 baseline entry
- `$this->scoringService->updateLeadScore($existingLead)` (existing branch) → explicit `$existingLead->score = $this->scoring->score($existingLead, $conversation); $existingLead->save();`
- `$this->scoringService->updateLeadScore($lead)` (new-lead branch) → explicit `$lead->score = $this->scoring->score($lead, $conversation); $lead->save();`

The `Tenant::where('api_key', ...)` and `Conversation::where('id', ..., 'tenant_id', ...)` queries are pre-existing — left alone. The `Conversation::where` *does* contain `'tenant_id'` and is currently baselined as a 2-count entry. Re-grep to verify (next step).

### Step 2: Remove the `Widget/LeadController.php` baseline entry

Open `phpstan-baseline.neon` and locate:
```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 2
			path: app/Http/Controllers/Api/V1/Widget/LeadController.php
```

Inspect the actual remaining raw-`tenant_id` call sites in the file:
```bash
grep -n "tenant_id" app/Http/Controllers/Api/V1/Widget/LeadController.php
```

There should be exactly ONE remaining: the `Conversation::where('id', ...)->where('tenant_id', $tenant->id)` call. The Lead lookup at line ~54 was converted in Step 1.

If the baseline `count: 2` was for both the Lead lookup AND the Conversation lookup, we now have 1 remaining violation. Two options:
- **(A)** Convert the Conversation lookup to Eloquent + forTenant too: `Conversation::query()->whereKey($request->conversation_id)->forTenant($tenant)->first()`. This needs verifying that `Conversation` has the `BelongsToTenant` trait applied (it does, per Plan A Task 2). If yes, this drops the baseline entry to 0 and we delete the full block.
- **(B)** Update the baseline `count: 2` to `count: 1` to acknowledge the remaining single violation.

**Pick (A)** — the rewrite is one-line, the trait is already on Conversation, and it's exactly the kind of opportunistic cleanup Plan A's master spec called out: "Files that no subsequent cluster touches remain in the baseline indefinitely; touched files convert opportunistically."

Apply this additional edit to `app/Http/Controllers/Api/V1/Widget/LeadController.php`. Find:
```php
        $conversation = Conversation::where('id', $request->conversation_id)
            ->where('tenant_id', $tenant->id)
            ->first();
```

Replace with:
```php
        $conversation = Conversation::query()
            ->whereKey($request->conversation_id)
            ->forTenant($tenant)
            ->first();
```

Then delete the entire `Widget/LeadController.php` block from `phpstan-baseline.neon` as written above.

### Step 3: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. If `Ignored error pattern ... was not matched`, re-grep the baseline.

### Step 4: Run the suite

```bash
php artisan test 2>&1 | tail -5
```

Expected: PASS. `WidgetLeadCaptureTest` exercises this controller end-to-end against the real DB, including the existing-lead branch.

If `WidgetLeadCaptureTest::test_existing_lead_blank_fields_can_be_filled_by_widget` fails because the asserted score values changed, this is the first time real WidgetLeadCaptureTest tests see post-merge scoring. Update assertions to match new expected values. Inspect with `php artisan test --filter=WidgetLeadCaptureTest 2>&1`.

### Step 5: Pint clean

```bash
./vendor/bin/pint --test app/Http/Controllers/Api/V1/Widget/LeadController.php 2>&1 | tail -3
```

Apply if needed.

### Step 6: Commit

```bash
git add app/Http/Controllers/Api/V1/Widget/LeadController.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(leads): Widget/LeadController uses canonical LeadScoring

DI swap from LeadScoringService → LeadScoring. Existing-lead and
new-lead branches both score explicitly (compute + save) instead of
calling the deleted updateLeadScore helper.

Lead + Conversation lookups opportunistically converted to forTenant —
shrinks the Cluster A baseline by 2 entries (LeadController block).

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster C)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 — Delete `LeadScoringService.php` + its old test

**Files:**
- Delete: `app/Services/Leads/LeadScoringService.php`
- Delete: `tests/Unit/Services/Leads/LeadScoringServiceTest.php`

### Step 1: Verify no caller still references the old class

```bash
grep -rn "LeadScoringService" app/ tests/ routes/ config/ 2>/dev/null
```

Expected: only the file itself and its test file appear. If any other file still imports it, STOP — Task 2 or 3 missed a call site.

### Step 2: Delete the files

```bash
git rm app/Services/Leads/LeadScoringService.php tests/Unit/Services/Leads/LeadScoringServiceTest.php
```

### Step 3: Run the suite

```bash
php artisan test 2>&1 | tail -5
```

Expected: PASS — fewer total tests than before (deleted ~18 from old service test). The new `LeadScoringTest` covers the same surface plus the new signals.

### Step 4: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -3
```

Expected: `[OK] No errors`.

### Step 5: Commit

```bash
git commit -m "$(cat <<'EOF'
refactor(leads): delete LeadScoringService + its test

Canonical scoring lives in App\Services\Leads\LeadScoring now. All
callers (LeadService::createLead/updateLead, Widget/LeadController) were
swapped in the previous commits. The old class + test file have no
remaining references.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster C)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 — Browser smoke (lead-capture round-trip + scoring sanity)

**Files:** none modified — local browser verification only.

**Purpose:** prove the unified scoring path works end-to-end against real local routes. Pest covers code correctness; the browser confirms the feature still works for both the chat-driven capture (LeadService path) and the widget lead-form capture (Widget/LeadController path).

### Step 1: Start the dev server (skip if already running on 8001)

```bash
php artisan serve --port=8001 > /tmp/laravel-serve.log 2>&1 &
sleep 2
curl -sI http://127.0.0.1:8001 | head -3
```

Expected: `HTTP/1.1 200 OK`.

### Step 2: Smoke flow 1 — chat-driven lead capture (LeadService path)

Open `http://127.0.0.1:8001/widget/test.html` in a browser (or use the Playwright MCP if available).

Send a chat message that includes contact info and at least one intent keyword:
```
Hi, my name is Cluster C Tester and my email is clusterc@example.com. What is your pricing?
```

Wait for the bot response.

Verify:
```bash
php artisan tinker --execute="\$l = \\App\\Models\\Lead::latest()->first(); echo json_encode(['id'=>\$l?->id,'name'=>\$l?->name,'email'=>\$l?->email,'score'=>\$l?->score,'temperature'=>app(\\App\\Services\\Leads\\LeadScoring::class)->temperature(\$l?->score ?? 0)]);"
```

Expected:
- `id` non-null, `name` = "Cluster C Tester", `email` = "clusterc@example.com"
- `score` ≥ 47 — at minimum: provided_email (20) + provided_name (10) + 1 user message (2) + pricing (25) = 57. Could be higher depending on the bot's name-extraction path adding bonus messages.
- `temperature` = "warm" or "hot"

If `score` is 0 or `temperature` is "cold", the scoring isn't firing — investigate.

Capture screenshot: `smoke-leadscoring-01-chat-capture.png`.

### Step 3: Smoke flow 2 — widget lead-form capture (Widget/LeadController path)

The widget lead-form route is `POST /api/v1/widget/lead`. There's no UI for it in the test page by default; trigger it directly with curl. Use the same tenant's api_key as the widget test page (find via tinker):
```bash
php artisan tinker --execute="echo \\App\\Models\\Tenant::where('slug', 'test-company')->value('api_key');"
```

(If the test tenant uses a different slug, look it up.)

Use the API key + the conversation ID from flow 1 to submit a duplicate-email widget lead-form capture:
```bash
API_KEY=<paste-from-above>
CONV_ID=<latest conversation id from flow 1>
curl -X POST http://127.0.0.1:8001/api/v1/widget/lead \
  -H "Content-Type: application/json" \
  -d "{\"api_key\":\"${API_KEY}\",\"conversation_id\":${CONV_ID},\"email\":\"clusterc@example.com\",\"phone\":\"+15551234567\",\"company\":\"Acme\"}" \
  | jq
```

Expected: `{"success": true, "lead_id": <same-id-as-flow-1>}` — the existing lead is reattached, not duplicated.

Verify the score updated:
```bash
php artisan tinker --execute="\$l = \\App\\Models\\Lead::latest()->first(); echo json_encode(['id'=>\$l?->id,'phone'=>\$l?->phone,'company'=>\$l?->company,'score'=>\$l?->score]);"
```

Expected: `phone` = "+15551234567", `company` = "Acme", `score` increased from flow 1 (now has phone + company signals).

Capture screenshot or terminal output: `smoke-leadscoring-02-widget-form.png`.

### Step 4: Tear down test data

```bash
php artisan tinker --execute="\\App\\Models\\Lead::where('email', 'clusterc@example.com')->delete();"
```

### Step 5: No commit needed — screenshots are temporary artifacts referenced in the PR description.

---

## Task 6 — Pint, /simplify, Pint, /simplify, PR

**Expected commit count when this PR is ready to push:** 4 feature commits from Tasks 1–4, plus 0–2 `style(pint): apply auto-fixes` commits per Pint pass, plus 0+ commits from each `/simplify` pass. Final total typically 5–8 commits.

### Step 1: First Pint pass

```bash
./vendor/bin/pint --test $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline)
```

If anything is flagged:
```bash
./vendor/bin/pint $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline)
php artisan test
git add -p
git commit -m "style(pint): apply auto-fixes — Cluster C"
```

### Step 2: First `/simplify` pass

Run `/simplify`. It dispatches three parallel reviewers (reuse / quality / efficiency). Apply real fixes; skip stylistic noise with a one-line reason.

Watch specifically for:
- The new `LeadScoring::score` method body — is the contact-info-then-message-content split clean, or could it be flattened with a fluent helper?
- The `dictionaryToSignal` map alongside the `dictionaries` array — would a value-object per dictionary (with weight + keywords + signal name) be cleaner? Probably not for v1 (introduces a new type with one use site) but worth a sanity check.
- Any narrative comments referencing "Cluster C" / "Task N" in the new code.

### Step 3: Second Pint pass

```bash
./vendor/bin/pint --test $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline)
```

If `/simplify` introduced new code that needs normalization, fix-and-commit.

### Step 4: Second `/simplify` pass

Run `/simplify` again. First pass's cleanups can introduce new minor issues.

### Step 5: Push branch and create PR

```bash
git push -u origin HEAD
```

```bash
gh pr create --title "feat(leads): merge LeadScoringService + LeadService scoring into canonical LeadScoring (Cluster C)" --body "$(cat <<'EOF'
## Summary

Cluster C of the architecture-deepening backlog. Collapses two parallel scoring services into one canonical module:

- **`LeadScoringService`** (fired on widget lead-form submission via `Widget/LeadController::capture`) — DELETED.
- **`LeadService::calculateScore` / `calculateInitialScore` / `scoreHighIntentKeywords`** (fired on chat-driven capture via `ChatController::captureLeadFromMessage` → `LeadService::captureFromConversation`) — DELETED.
- **`App\Services\Leads\LeadScoring`** — NEW. Single source of truth for the signal set, weight table, keyword dictionaries, and temperature thresholds.

Same Lead, same scoring math, regardless of entry point. Whichever capture path runs last no longer overwrites with a divergent number.

`LeadService`, `Widget/LeadController` queries opportunistically converted to `forTenant($tenant)`, **shrinking the Cluster A baseline from 47 → 44 violations (25 → 22 blocks)**.

## Deploy steps

No migrations; no env vars; no route changes. Standard merge → deploy.

**Rollback:** `git revert <merge-sha>` is sufficient. No DB schema change.

## :warning: Behavior changes

- **`LeadScoringService` class deleted.** Anything injecting it will fail to resolve. The only known caller (`Widget/LeadController`) was swapped to `LeadScoring`.
- **Scoring math is unified.** Existing leads keep their stored scores; future score recalculations use the merged signal set. Score drift on next recalc is expected:
  - **+ message_sent** (+2 per user message): every conversation-driven score gains a few points.
  - **+ long_conversation** (+5 when ≥5 messages): replaces the dropped `high_engagement` (which fired binary +15 above 5 messages).
  - **+ return_visitor** (+10 when lead has ≥2 linked conversations).
  - **+ provided_company** (+10).
  - **+ contact intent** (+10 — implementer-chosen weight; see "Implementer-chosen weights" below).
  - **+ purchase intent** (+15 — implementer-chosen weight).
  - **= pricing** and **demo** weights unchanged (LeadScoringService values won at 25 / 30; LeadService's lower 15 / 20 are gone).
  - **= contact-info signals** unchanged (all three services agreed at 20 / 15 / 10).
  - **= negative_sentiment** unchanged (−10).
- **`LeadScoring::score(Lead, ?Conversation)` is the public API.** No `updateLeadScore` helper. Callers compute the score and persist explicitly. Keeps the scoring service free of side effects; tests and callers control when/how the save lands.
- **`LeadService::updateLead` applies contact-info updates BEFORE rescoring.** Previously the old code computed contact-info bonuses from the `$contactInfo` array argument; now `LeadScoring` reads directly from `$lead` attributes, so updates must land on the in-memory model first. Behavior change only for the narrow case where `$lead->company` was already set and `$contactInfo['company']` was passed — both arrived at the same +10, so net score is unchanged.
- **Scoring is now full re-score, not incremental.** The old `LeadService::calculateScore` started from `$lead->score` and added bonuses for newly-arrived signals. The new `LeadScoring::score()` computes the total from current state every time. Practical consequence: a lead's score is no longer monotonically non-decreasing across updates — it can go down if a signal stops applying (e.g., the matching conversation has its negative-sentiment message edited away, or a previous-conversation gets reassigned to a different lead). If any analytics/dashboard relies on monotonic scores, flag it; nothing in-repo today does.

## Implementer-chosen weights

The master spec lists `contact` and `purchase` in the union of keyword dictionaries but doesn't specify their weights. This PR assigns:
- `asked_about_contact` = **+10** — light signal (asking to be contacted ≈ comparable to `provided_name`).
- `asked_about_purchase` = **+15** — strong intent without commitment (between contact and pricing/demo).

If these values feel wrong, flag in review — they're isolated to one line each in `LeadScoring::$weights`.

## Test plan

- [x] `LeadScoringTest` — 21 assertions covering every signal (contact-info, intent, engagement, negative), conversation-fallback semantics, case-insensitivity, clamping, temperature thresholds
- [x] Updated existing tests in `WidgetLeadCaptureTest` if score-value assertions changed
- [x] Full Pest suite passes
- [x] `./vendor/bin/phpstan analyse` → `[OK] No errors`; baseline shrunk from 47 → 44 violations (3 blocks removed)
- [x] Browser smoke: chat-driven lead capture + widget lead-form capture; both produce the same score for the same lead. Screenshots `smoke-leadscoring-{01,02}.png` attached.

## Architecture

Cluster C of the 4-cluster architecture-deepening initiative. Standalone PR; doesn't share files with Cluster B (which shipped as PR #19).

- Spec: `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`
- Plan: `docs/superpowers/plans/2026-05-15-lead-scoring.md`
- Prior clusters: PR #18 (Cluster A — tenant scoping), PR #19 (Cluster B — knowledge pipeline)

:robot: Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

### Step 6: Wait for CI green and merge

Watch the PR's checks. Fix any failures and re-push. Merge once green.

### Step 7: Update memory after merge

Save a project memory entry at `~/.claude/projects/-Users-sam-Dev-laravel-chatbot/memory/arch_cluster_c_shipped.md` capturing:
- PR # + merge SHA
- Baseline shrinkage (47 → 44)
- Behavior-change note about the implementer-chosen weights (in case it comes up later)
- Cluster D + E status

---

## Self-review summary

**Spec coverage check (Cluster C section of `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`):**

- D2 approach (new canonical `LeadScoring`, rename from `LeadScoringService`) → Task 1 creates the new file with the canonical name.
- Public interface (`score`, `temperature`) → Task 1 implements both with the exact signatures from the spec.
- Owns all signals/weights/keywords/thresholds → Task 1's `$weights`, `$dictionaries`, `$dictionaryToSignal`, `temperature()` body.
- Signal reconciliation (R2): baseline = LeadScoringService set + provided_company, message_sent, long_conversation, return_visitor; drop high_engagement → Task 1 weights table.
- Weight overlap: pricing=25, demo=30, contact-info unchanged → Task 1 weights table.
- Negative signal preserved → Task 1 weights table includes `negative_sentiment => -10`.
- Keyword dictionaries union → Task 1 `$dictionaries` array.
- LeadService changes (delegate `captureFromConversation` + `updateLead`; delete private calculation methods + constants) → Task 2.
- Widget/LeadController change (DI swap) → Task 3.
- LeadScoringService deletion → Task 4.

**Out-of-scope items confirmed not addressed:**
- R3 fresh-from-product redesign of signal set
- Sentiment analysis beyond keyword matching
- LLM-based intent detection
- Multi-language keyword sets

**Placeholder scan:** no `TBD`, no `TODO`, no "implement later", no "Similar to Task N". Every code block is complete.

**Type consistency:** the `LeadScoring` class name + `score`/`temperature` method signatures used in Tasks 1, 2, 3 all match. `Lead`/`Conversation` model references are consistent.

**Baseline-shrink discipline:** Tasks 2 + 3 each remove specific baseline blocks; Task 3 explicitly handles the case where one of the two raw-`tenant_id` violations in `Widget/LeadController` is the Conversation lookup (separately converted as part of the same task).

**Scoring-math test coverage:** Task 1's test class covers all 14 weighted signals individually, plus clamping (0 and 100), case-insensitivity, assistant-message exclusion, conversation-fallback semantics. That's 21 distinct tests against a well-defined interface.

**Outstanding judgment call:** `asked_about_contact = +10` and `asked_about_purchase = +15` are implementer-chosen weights not pinned by the spec. They're surfaced in the PR description so a reviewer can object before merge. Picked to slot into the existing scale without dominating short-conversation scores.
