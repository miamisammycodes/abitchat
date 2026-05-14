# Frontend Polish & a11y — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close five medium-severity frontend / a11y findings — broken Settings link in the client layout (M-NEW-15), paginator links still keyboard-focusable when disabled (M7), plan-toggle has no in-flight guard so double-click double-PATCHes (M-NEW-12), Lead-Show transcript misleading when a lead has multiple conversations (M5), and modal forms don't reset on Cancel so old data leaks across reopens (M1).

**Verification approach — important context:** this codebase has **no JS test runner** (no Jest, no Vitest, no Vue test utilities). The only PHP-side surface for these fixes is one route-level test for M-NEW-15. Everything else is **verified via Playwright browser smoke in Task 6** — that's the test surface for Vue component fixes here. The plan acknowledges this up front so reviewers don't expect Pest tests where none exist.

**Architecture:**
- **M-NEW-15**: delete the broken `<Link href="/dashboard/settings">` block from `ClientLayout.vue`. No route → no link. Pest regression guard asserts the route doesn't exist.
- **M7**: paginator `<Link>` blocks gain `:tabindex` and `:aria-disabled` bindings that reflect `link.url` presence — disabled paginator entries become unfocusable and announce their disabled state.
- **M-NEW-12**: introduce a `togglingIds` reactive `Set<number>` on `Admin/Plans/Index.vue`; mark the plan id as in-flight before the PATCH, clear on success/error. Toggle component receives `:disabled="togglingIds.has(plan.id)"`.
- **M5**: `Lead/Show.vue` adds a clear "Showing latest of N conversations" banner above the transcript when `lead.conversations.length > 1`. Optionally a dropdown to pick a specific conversation, but that's deferred — banner is sufficient to address the audit's "misleading" framing.
- **M1**: on Cancel, every modal calls the corresponding Inertia `useForm` instance's `.reset()` before closing. Touches `Lead/Show.vue` (status + score) and `Admin/Clients/Show.vue` (status + plan).

**Tech Stack:** Laravel 13+, PHP 8.3+, Vue 3 (Composition API + `<script setup>`), Inertia.js v1+, Tailwind CSS v4. Pest for the one PHP regression guard. Playwright for browser smoke.

**Spec reference:** `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` — Cluster 6.

**Order rationale:** smallest/safest first.
- Task 1 — **M-NEW-15** (one-line removal + one Pest test)
- Task 2 — **M7** (a11y attribute additions on a single component)
- Task 3 — **M-NEW-12** (single component, established `togglingId` pattern from the spec's cross-cluster note)
- Task 4 — **M5** (UX banner on Lead/Show)
- Task 5 — **M1** (4 modal cancel handlers across 2 components — last because biggest blast radius)
- Task 6 — closing (Playwright smoke + Pint + `/simplify` ×2 + PR)

---

## Task 0: Verification pass against current `main`

- [ ] **Step 1: Verify M-NEW-15 — broken Settings link**

```bash
grep -n "/dashboard/settings" resources/js/Layouts/ClientLayout.vue routes/web.php
```
Expected: hit in `ClientLayout.vue` (the `<Link href="/dashboard/settings">`), no hit in `routes/web.php` (no matching route).

- [ ] **Step 2: Verify M7 — paginator links lack a11y attrs**

```bash
grep -nB1 -A8 "v-for=\"link in conversations.links\"" resources/js/Pages/Client/Conversations/Index.vue
```
Expected: `<Link>` has class-based disabled handling (`!link.url && 'pointer-events-none ...'`) but no `:tabindex` or `:aria-disabled` bindings.

- [ ] **Step 3: Verify M-NEW-12 — plan toggle has no guard**

```bash
grep -nE "togglePlanStatus|togglingIds" resources/js/Pages/Admin/Plans/Index.vue
```
Expected: `togglePlanStatus` exists at line ~55 and calls `router.patch` directly; no `togglingIds`.

- [ ] **Step 4: Verify M5 — Lead/Show transcript shows only latest**

```bash
grep -nE "conversations\?\.length|lead\.conversation\?\.messages" resources/js/Pages/Client/Leads/Show.vue
```
Expected: count shown (`{{ lead.conversations?.length || 0 }} conversation(s) with this lead`) but transcript iterates only `lead.conversation.messages` (singular).

- [ ] **Step 5: Verify M1 — modal cancels don't reset forms**

```bash
grep -nE "showStatusModal = false|showScoreModal = false|showPlanModal = false" resources/js/Pages/Client/Leads/Show.vue resources/js/Pages/Admin/Clients/Show.vue
```
Expected: every Cancel button just sets `showXModal = false` with no preceding `form.reset()`.

- [ ] **Step 6: Proceed**

All five findings live.

---

## Task 1: M-NEW-15 — Remove broken Settings link

**Goal:** `<Link href="/dashboard/settings">` in `ClientLayout.vue` points to a route that doesn't exist. Clicking it 404s. Remove the link.

**Files:**
- Modify: `resources/js/Layouts/ClientLayout.vue` (remove the `<Link>` + import + separator if orphaned)
- Test: `tests/Feature/ClientSettingsRouteAbsentTest.php` (new — regression guard)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ClientSettingsRouteAbsentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ClientSettingsRouteAbsentTest extends TestCase
{
    public function test_dashboard_settings_route_returns_404(): void
    {
        $this->actingAsTenantUser();
        $this->get('/dashboard/settings')->assertNotFound();
    }
}
```

This is a regression guard: if a future commit adds `/dashboard/settings` to routes/web.php without also restoring a settings link in `ClientLayout.vue`, this test still passes (it asserts the URL is 404 — which is no longer true). The opposite case (link gets re-added to layout pointing at a non-route) isn't caught by this PHP-side test — that's why the cluster's browser smoke covers it instead.

Actually rethink: the value here is documenting the "no settings route" state for now. If a future settings PR adds the route, this test will fail and the PR author has to delete the test alongside adding the route — that's the intended workflow.

- [ ] **Step 2: Run test to verify it passes today**

```bash
php artisan test --filter=test_dashboard_settings_route_returns_404
```
Expected: PASS (no settings route exists).

- [ ] **Step 3: Remove the broken link from `ClientLayout.vue`**

In `resources/js/Layouts/ClientLayout.vue`, find the dropdown menu block (around lines 180–199):

```vue
<div class="px-4 py-2 border-b">
    <p class="text-sm font-medium">{{ user?.name }}</p>
    <p class="text-xs text-muted-foreground">{{ user?.email }}</p>
</div>
<Link
    href="/dashboard/settings"
    class="flex items-center gap-2 px-4 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
>
    <Settings class="h-4 w-4" />
    Settings
</Link>
<Separator />
<button
    @click="logout"
    ...
```

Delete the `<Link href="/dashboard/settings">...</Link>` block AND the orphan `<Separator />` that no longer separates anything from the items above. The dropdown becomes name/email → logout. Also remove the now-unused `Settings` import from the `lucide-vue-next` line at the top:

```bash
grep -n "Settings" resources/js/Layouts/ClientLayout.vue
```
Expected after removal: 0 hits.

If `Separator` is still used elsewhere in the file, keep its import; otherwise also remove from imports.

- [ ] **Step 4: Run the suite**

```bash
php artisan test
```
Expected: still passing (the new test passes; nothing else affected).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/ClientLayout.vue tests/Feature/ClientSettingsRouteAbsentTest.php
git commit -m "$(cat <<'EOF'
fix(layout): remove broken /dashboard/settings link (M-NEW-15)

The link in the user dropdown pointed to a route that never existed,
producing a 404 on click. Remove the link block, the orphan Separator,
and the unused Settings icon import. Pest regression guard asserts
the URL stays a 404 until a future PR adds both the route and the
link together.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: M7 — Paginator links accessibility

**Goal:** Inertia `<Link>` paginator items in `Conversations/Index.vue` currently disable themselves via `pointer-events-none` styling only — they're still focusable via Tab and screen readers announce them as live links. Add `:tabindex` and `:aria-disabled` bindings.

**Files:**
- Modify: `resources/js/Pages/Client/Conversations/Index.vue` (paginator block, near end of file)

- [ ] **Step 1: Update the paginator `<Link>` to be a11y-aware**

In `resources/js/Pages/Client/Conversations/Index.vue`, find the paginator block (search for `v-for="link in conversations.links"`). Update the `<Link>` element:

```vue
<Link
    v-for="link in conversations.links"
    :key="link.label"
    :href="link.url || ''"
    v-html="link.label"
    :tabindex="link.url ? null : -1"
    :aria-disabled="!link.url"
    :class="[
        'rounded px-3 py-1 text-sm',
        link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
        !link.url && 'pointer-events-none text-muted-foreground/50',
    ]"
    preserve-state
    preserve-scroll
/>
```

The new `:tabindex="link.url ? null : -1"` removes disabled links from the tab order. `null` omits the attribute entirely on enabled links (anchors are focusable by default — explicit `tabindex="0"` would be redundant). `:aria-disabled="!link.url"` announces the disabled state to assistive tech.

- [ ] **Step 2: Confirm no PHP suite regression**

```bash
php artisan test
```
Expected: all green (no PHP change).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Client/Conversations/Index.vue
git commit -m "$(cat <<'EOF'
fix(a11y): paginator disabled links are unfocusable + aria-disabled (M7)

Disabled paginator entries previously relied on pointer-events-none
for visual disable but were still keyboard-focusable and announced
as live links. tabindex=-1 removes them from tab order; aria-disabled
surfaces the state to assistive tech.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: M-NEW-12 — Plan toggle in-flight guard

**Goal:** `Admin/Plans/Index.vue` calls `router.patch` on every toggle click with no guard. Double-clicks send two PATCHes; the final state depends on which response lands last. Add a per-row `togglingIds` Set; disable the toggle while the PATCH is in flight.

Per the spec's cross-cluster patterns: "Front-end disable-during-flight uses a per-row id ref (`togglingId.value`), not a global `isSubmitting` boolean." This fix follows that pattern.

**Files:**
- Modify: `resources/js/Pages/Admin/Plans/Index.vue` (script setup + template binding)

- [ ] **Step 1: Locate the toggle handler and the bound component**

Read the relevant lines:
```bash
grep -nB1 -A5 "togglePlanStatus\|@update:checked" resources/js/Pages/Admin/Plans/Index.vue
```
Expected:
- `togglePlanStatus(plan)` at around line 55 — calls `router.patch(route('admin.plans.toggle', plan.id), {}, { preserveState: true })`
- `@update:checked="togglePlanStatus(plan)"` on the toggle UI control at around line 189

- [ ] **Step 2: Add a `togglingIds` ref and gate the handler**

In the `<script setup>` block, near the other reactive state (probably after the imports), add:

```js
import { ref } from 'vue'
// ... other imports ...

const togglingIds = ref(new Set())
```

(If `ref` is already imported, don't add a duplicate.)

Update `togglePlanStatus`:

```js
const togglePlanStatus = (plan) => {
    if (togglingIds.value.has(plan.id)) {
        return
    }
    togglingIds.value.add(plan.id)
    router.patch(route('admin.plans.toggle', plan.id), {}, {
        preserveState: true,
        onFinish: () => {
            togglingIds.value.delete(plan.id)
        },
    })
}
```

The `onFinish` Inertia callback fires on both success and error.

- [ ] **Step 3: Disable the toggle while in-flight**

On the template element with `@update:checked="togglePlanStatus(plan)"` (line ~189), add:

```vue
:disabled="togglingIds.has(plan.id)"
```

The exact attribute name depends on the toggle component. If it's a custom `<Switch>` or `<Toggle>` that accepts `:disabled`, this works directly. If it's a native `<input type="checkbox">`, same attribute.

Verify the toggle's prop API — read the component or its TypeScript types if available:
```bash
grep -nE "<Switch\|<Toggle\|defineProps" resources/js/Components/ui/*.vue | head -10
```
If the toggle component doesn't accept `:disabled`, fall back to a wrapper `<div class="opacity-50 pointer-events-none">` conditionally applied. Cleaner: just bind `:disabled` if supported.

- [ ] **Step 4: Verify reactivity at runtime**

`ref(new Set())` Vue 3 reactivity through `.add()` / `.delete()` mutations should work via the collection-handler proxy, but it's a known gotcha. Briefly verify in a browser before committing:

1. Open `/admin/plans` in the dev browser.
2. Open DevTools → console.
3. Click the plan toggle once and confirm the toggle appears disabled while the request is in flight.

If the disabled state doesn't apply visually, the reactivity isn't tracking through the Set. Fallback: replace `ref(new Set())` with `reactive(new Set())` (no `.value` indirection) and update both the handler and the template binding accordingly.

- [ ] **Step 5: Confirm no PHP suite regression**

```bash
php artisan test
```
Expected: 287 still passing.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/Plans/Index.vue
git commit -m "$(cat <<'EOF'
fix(admin): guard plan toggle against double-PATCH (M-NEW-12)

A double-click previously sent two router.patch calls; the final
plan state depended on which response landed last. togglingIds Set
tracks in-flight plan ids, the handler short-circuits if the id is
already toggling, and the toggle component is :disabled while
in-flight. Inertia's onFinish callback clears the id on both
success and error.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: M5 — Multi-conversation transcript indicator

**Goal:** `Lead/Show.vue` shows `{{ lead.conversations?.length || 0 }} conversation(s) with this lead` (count) AND iterates `lead.conversation.messages` (singular — only the latest). A user reading the transcript may not realize older conversations exist. Add an explicit banner when there are multiple conversations.

**Files:**
- Modify: `resources/js/Pages/Client/Leads/Show.vue` (around the transcript card)

- [ ] **Step 1: Read the transcript card structure**

```bash
sed -n '240,290p' resources/js/Pages/Client/Leads/Show.vue
```
Locate the `<CardContent>` block that wraps the conversation list. The current shape:
- `{{ lead.conversations?.length || 0 }} conversation(s) with this lead` text inside a `<CardDescription>` or similar
- A `<div v-if="lead.conversation?.messages?.length" ...>` that lists messages

- [ ] **Step 2: Add a banner above the transcript when there are multiple conversations**

Above the existing message-list `<div>`, insert:

```vue
<div
    v-if="(lead.conversations?.length ?? 0) > 1"
    class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200"
    role="note"
>
    Showing the latest of {{ lead.conversations.length }} conversations. Older transcripts aren't displayed on this page.
</div>
```

Place it inside the `<CardContent>`, immediately before the message-list `<div v-if="lead.conversation?.messages?.length" ...>`.

`role="note"` is appropriate for an inline informational notice (per WAI-ARIA), distinct from `role="alert"` which would announce more urgently to screen readers.

The amber color matches Tailwind's "warning/caveat" convention used elsewhere in the project — if you find another notice/banner style in the codebase already, match it instead. Quick scan:
```bash
grep -rln "bg-amber-50\|role=\"note\"" resources/js/ | head -5
```
If a precedent exists, match it; otherwise the above is fine.

- [ ] **Step 3: Confirm no PHP suite regression**

```bash
php artisan test
```
Expected: 287 still passing.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Client/Leads/Show.vue
git commit -m "$(cat <<'EOF'
fix(leads): banner when lead has multiple conversations (M5)

The transcript card shows only the latest conversation's messages,
but a lead can have many. Add an inline note above the transcript
when conversations.length > 1 — "Showing the latest of N
conversations" — so users don't assume the displayed transcript is
the complete history.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: M1 — Modal forms reset on Cancel

**Goal:** When a user opens a modal, types/edits, then clicks Cancel and reopens, the previously-typed values are still there. Reset the underlying `useForm` instance on Cancel.

**Files:**
- Modify: `resources/js/Pages/Client/Leads/Show.vue` (2 modals: status, score)
- Modify: `resources/js/Pages/Admin/Clients/Show.vue` (2 modals: status, plan)

- [ ] **Step 1: Add cancel handlers to `Lead/Show.vue`**

In the `<script setup>` block, add named functions for each cancel:

```js
function cancelStatus() {
    statusForm.reset()
    statusForm.clearErrors()
    showStatusModal.value = false
}

function cancelScore() {
    scoreForm.reset()
    scoreForm.clearErrors()
    showScoreModal.value = false
}
```

Place these near the existing `updateStatus` / `adjustScore` functions. `reset()` restores values to construction-time defaults; `clearErrors()` is needed separately because modern Inertia (`@inertiajs/vue3` v1+) doesn't clear validation errors from a previous failed Save when `reset()` is called.

Update the Cancel buttons in the template:

- Status modal (around line 312): `@click="showStatusModal = false"` → `@click="cancelStatus"`
- Score modal (around line 369): `@click="showScoreModal = false"` → `@click="cancelScore"`

Also update the backdrop click (which acts as a "cancel" gesture):
- Status modal (around line 288): `@click="showStatusModal = false"` → `@click="cancelStatus"`
- Score modal (around line 327): `@click="showScoreModal = false"` → `@click="cancelScore"`

- [ ] **Step 2: Add cancel handlers to `Admin/Clients/Show.vue`**

Inspect modal structure first:
```bash
grep -nE "showStatusModal|showPlanModal|statusForm|planForm" resources/js/Pages/Admin/Clients/Show.vue | head -15
```

Add named handlers:

```js
function cancelStatus() {
    statusForm.reset()
    statusForm.clearErrors()
    showStatusModal.value = false
}

function cancelPlan() {
    planForm.reset()
    planForm.clearErrors()
    showPlanModal.value = false
}
```

Update all `@click="showStatusModal = false"` and `@click="showPlanModal = false"` Cancel/backdrop sites to use the handlers. There are typically 4 sites per modal (Cancel button + backdrop overlay div), but check the actual file.

- [ ] **Step 3: Verify no remaining bare `Modal = false` cancels in the changed files**

```bash
grep -n "Modal = false" resources/js/Pages/Client/Leads/Show.vue resources/js/Pages/Admin/Clients/Show.vue
```
Expected: zero hits. Every modal-closing path should now go through a `cancelX` handler.

(Note: this is more rigorous than strictly needed — the *success* paths (after a successful `updateStatus` / `updatePlan`) also close the modal but don't need a reset since the form was just submitted and Inertia clears it. Those paths use `showXModal.value = false` inside `onSuccess` callbacks, which won't match the regex `Modal = false` (note the `.value`). Verify the regex check excludes those.)

A tighter check:
```bash
grep -nE "@click=\"showStatus|@click=\"showScore|@click=\"showPlan" resources/js/Pages/Client/Leads/Show.vue resources/js/Pages/Admin/Clients/Show.vue
```
Expected hits should all be `cancelStatus` / `cancelScore` / `cancelPlan` (the handlers), or `showXModal = true` (opening — those stay as-is).

- [ ] **Step 4: Confirm no PHP suite regression**

```bash
php artisan test
```
Expected: 287 still passing.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Client/Leads/Show.vue resources/js/Pages/Admin/Clients/Show.vue
git commit -m "$(cat <<'EOF'
fix(modals): reset form state on Cancel and backdrop close (M1)

Previously, opening a modal, typing, cancelling, and reopening left
the old values in place. Each Cancel button and backdrop overlay
now calls a cancelX handler that resets the underlying useForm
instance before closing the modal. Covers status + score in
Lead/Show and status + plan in Admin/Clients/Show.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Browser smoke, Pint, /simplify, PR

Per CLAUDE.md's workflow (Pint → /simplify → Pint → /simplify → PR), with browser smoke first. **Browser smoke is the primary verification surface for this cluster** since there are no JS unit tests.

- [ ] **Step 1: Boot dev environment**

```bash
php artisan serve --port=8001
npm run dev
```

- [ ] **Step 2: M-NEW-15 smoke**

1. Log in as `test@example.com` / `password`.
2. Click the user avatar dropdown in the top-right.
3. Confirm: name + email row → logout. **NO "Settings" entry.**

- [ ] **Step 3: M7 paginator a11y smoke**

1. Navigate to `/conversations` (requires at least 20+ conversations to show pagination, or temporarily lower the per-page limit). If pagination isn't visible on the dev seed data, skip and rely on the inspect-the-DOM check below.
2. Tab through the page. The disabled paginator entries (typically the «  prev arrow on page 1, » next arrow on last page) should be SKIPPED by Tab.
3. Inspect with browser DevTools: confirm disabled `<a>` elements have `tabindex="-1"` and `aria-disabled="true"`.

If pagination isn't visible on dev seed data:
```bash
php artisan tinker --execute="echo App\Models\Conversation::where('tenant_id', App\Models\Tenant::where('slug','test-company')->value('id'))->count();"
```
If under 20, add more or just inspect the rendered HTML for a representative paginator state.

- [ ] **Step 4: M-NEW-12 plan toggle smoke**

1. Log in as `admin@example.com` / `password`.
2. Navigate to `/admin/plans`.
3. Find a plan with the toggle (any).
4. **Triple-click the toggle rapidly.**
5. Confirm: only ONE PATCH request fires (check the Laravel Debugbar Network panel or browser DevTools Network tab). The toggle should remain disabled during the request; subsequent clicks during the in-flight period should be ignored.

- [ ] **Step 5: M5 multi-conversation banner smoke**

1. As the client, navigate to a lead that has multiple conversations. If none in dev:
   ```bash
   php artisan tinker --execute="echo App\Models\Lead::withCount('tenant')->get()->map(fn(\$l) => ['id'=>\$l->id, 'name'=>\$l->name])->toJson();"
   ```
   Find one with multiple conversations or temporarily create one.
2. Visit `/leads/{id}`.
3. Confirm the amber banner appears above the transcript: "Showing the latest of N conversations. Older transcripts aren't displayed on this page."
4. Visit a lead with only ONE conversation — banner should NOT appear.

- [ ] **Step 6: M1 modal-reset smoke**

For each of: Lead status modal, Lead score modal, Admin Client status modal, Admin Client plan modal:

1. Open the modal.
2. Change a value (radio button, range slider, dropdown).
3. Click Cancel.
4. Reopen the modal.
5. Confirm the value is the **original** (not the changed-then-cancelled value).
6. Also test backdrop-click as a cancel gesture — same expectation.

- [ ] **Step 7: Pint pass 1**

JS isn't covered by Pint. The only PHP files touched are the new `ClientSettingsRouteAbsentTest`:

```bash
./vendor/bin/pint --test tests/Feature/ClientSettingsRouteAbsentTest.php
```
If flagged, apply fixes.

- [ ] **Step 8: /simplify pass 1**

Run `/simplify`. The cluster touches mostly Vue files where /simplify's PHP-centric reviewers have less to say, but it'll still catch obvious patterns (copy-paste, redundant state). Apply substantive findings; skip stylistic noise.

- [ ] **Step 9: Pint pass 2** — re-test, no-op expected.

- [ ] **Step 10: /simplify pass 2** — address newly-introduced issues if any.

```bash
php artisan test
```
Expected: 287 + 1 = 288 passing (1 new from Task 1's regression guard).

- [ ] **Step 11: Open the PR**

```bash
git push -u origin HEAD
gh pr create --title "fix(frontend): close cluster-6 polish & a11y findings" --body "$(cat <<'EOF'
## Summary

Cluster 6 — frontend polish & a11y. Final cluster of the medium-backlog spec.

- **M-NEW-15** — Removed broken \`<Link href="/dashboard/settings">\` from \`ClientLayout.vue\`. The link 404'd on click; no settings page exists. Pest regression guard locks the URL as 404 until a future PR adds both the route and the link together.
- **M7** — Paginator \`<Link>\` entries in \`Conversations/Index.vue\` gain \`:tabindex\` and \`:aria-disabled\` bindings reflecting \`link.url\` presence. Disabled paginator items are now skipped by Tab and announced as disabled to assistive tech.
- **M-NEW-12** — Plan-toggle in \`Admin/Plans/Index.vue\` gains a per-row \`togglingIds\` Set guard. Double-clicks send at most one PATCH. Toggle component receives \`:disabled\` during the in-flight period; Inertia's \`onFinish\` callback clears the id on success and error.
- **M5** — \`Lead/Show.vue\` shows an inline amber banner above the transcript when the lead has more than one conversation: "Showing the latest of N conversations. Older transcripts aren't displayed on this page." Eliminates the user's false assumption that the visible transcript is the lead's complete history.
- **M1** — All four modal Cancel handlers (Lead status, Lead score, Admin Client status, Admin Client plan) now call \`form.reset()\` before closing. Backdrop clicks honor the same handler. Reopening a cancelled modal shows the original values, not the discarded edits.

## Deploy steps

1. Merge.
2. No migrations.
3. JS bundle rebuild (\`npm run build\`) is part of the normal deploy.

## ⚠️ Behavior changes

| Change | Who's affected | Mitigation |
|---|---|---|
| User dropdown no longer shows "Settings" | Anyone who saw the broken link | None — link was broken; clicking 404'd |
| Disabled paginator items skipped by Tab + announce as disabled | Keyboard / screen-reader users | None — strictly looser keyboard navigation |
| Plan toggle ignores rapid double-clicks | Admins who double-clicked toggles | None — silent improvement; final state matches single-click |
| Multi-conversation lead transcript shows a banner | Tenants with leads that have multiple conversations | None — informational only |
| Cancelled modal forms reset on reopen | Anyone who cancelled and reopened | None — discarded edits stay discarded |

## Test plan — verification approach note

This cluster is all Vue component work in a codebase with **no JS test runner** (no Jest/Vitest/Vue test utils). The primary verification surface is **Playwright browser smoke** described below, with one PHP-side regression guard for M-NEW-15. The lack of unit-level Vue tests is acknowledged; future cluster-6-style work that needs richer JS testing should set up Vitest as a separate workstream.

- [x] \`php artisan test\` — 288 passing (287 baseline + 1 regression guard)
- [x] Pint clean on the one new PHP file
- [x] \`/simplify\` ×2
- [x] **Browser smoke**:
  - M-NEW-15: user dropdown shows no "Settings" entry
  - M7: disabled paginator entries skipped by Tab, have \`tabindex="-1"\` and \`aria-disabled="true"\` in the DOM
  - M-NEW-12: triple-clicking the plan toggle fires exactly one PATCH
  - M5: amber banner appears above transcript only when \`lead.conversations.length > 1\`
  - M1: open modal → change value → Cancel → reopen shows original values; backdrop-click same behavior

## Architecture notes

- \`togglingIds: Set<number>\` is the established pattern for per-row in-flight guards per the spec's cross-cluster note. Avoid a global \`isSubmitting\` boolean — it disables every toggle on the page during any one in-flight call.
- \`role="note"\` (not \`role="alert"\`) on the M5 banner — it's informational, not urgent. \`aria-disabled\` on M7 paginator items — distinguishes a disabled-but-still-in-DOM link from a missing link.
- \`useForm().reset()\` (Inertia) restores values to whatever was passed at form construction. For \`status: props.lead.status\` this means the lead's status as of page load, not the database's current value. Edge case (admin updates status concurrently) is acceptable — page reload resolves.

## Links

- Spec: \`docs/superpowers/specs/2026-05-12-medium-backlog-design.md\` (Cluster 6)
- Plan: \`docs/superpowers/plans/2026-05-14-frontend-polish.md\`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 12: Update memory after merge**

Save a memory entry capturing:
- Cluster 6 closed; **medium-backlog complete** — all clusters 1–6 shipped
- Frontend pattern: per-row in-flight guard via `Set<id>`, not global boolean
- The "no JS test runner" gap — a future workstream should set up Vitest if frontend testing depth is needed
- Production deploy across all 5 medium clusters still pending (no Cluster 6 schema/data concerns)

---

## Out of scope

- **Setting up Vitest / a JS test runner** — separate workstream; not blocking cluster 6.
- **Adding a real settings page at \`/dashboard/settings\`** — separate feature work; this PR just removes the broken link.
- **Conversation switcher in Lead/Show** — banner is sufficient per the audit; switcher is feature work.
- **A unified `<Modal>` component with built-in reset semantics** — flagged in earlier audits as a follow-up; not blocking these fixes.
- **Replacing the manual paginator with a standardized `<Pagination>` component** — the audit only asked for a11y on the existing one.
