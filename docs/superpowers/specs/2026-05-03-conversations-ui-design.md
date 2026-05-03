# Conversations UI (M8.3) — Design

**Status:** Approved, ready for implementation planning
**Roadmap item:** M8.3 — Conversation list & detail (was marked "❌ not built" in `ROADMAP.md`)
**Approach chosen:** Originally "Read layer + data hygiene" (Approach 2); collapsed to "Read layer only" (Approach 1) at implementation time after discovering the four "unused columns" never actually existed in the `conversations` table — they only ever appeared in the stale ROADMAP M2.5 schema example. No migration is needed. See change log at the bottom of this doc.

## Goal

Give client users a way to see what their chatbot is actually saying. Today the `conversations` and `messages` tables fill up via the widget API but the dashboard has no UI for them, which is a major product gap.

## Scope

In scope:
- List page with filters and pagination
- Detail page with full transcript and metadata sidebar
- Archive / unarchive a conversation (soft action, reversible)
- Export a single conversation as plain text

Explicitly out of scope (pushed to follow-ups):
- Hard delete of conversations or messages
- Per-message token / retrieved-chunk display (would require also writing `messages.tokens_used` and persisting RAG context, which today goes unrecorded)
- List-level CSV export
- Free-text search across message content
- Refactoring `ChatController` orchestration into a `ConversationTurn` Module (architecture deepening candidate #1; better as its own focused commit)
- Tenant-scope global enforcement (architecture candidate #3)

## Routes

Five routes added to `routes/web.php` inside the existing `auth` middleware group, mirroring the `Leads` block:

```
GET    /conversations                          → ConversationController@index
GET    /conversations/{conversation}           → ConversationController@show
GET    /conversations/{conversation}/export    → ConversationController@export
PUT    /conversations/{conversation}/archive   → ConversationController@archive
PUT    /conversations/{conversation}/unarchive → ConversationController@unarchive
```

Tenant scoping is enforced explicitly in the controller via `where('tenant_id', auth()->user()->tenant_id)` on every query, matching the existing `LeadController` pattern. Cross-tenant access returns 404, not 403, to avoid leaking the existence of another tenant's resources.

## Controller

**`app/Http/Controllers/Client/ConversationController.php`** — five actions.

`index` query (single chain, eager-loaded to avoid N+1):

```php
Conversation::forTenant($tenant)
    ->withStatus($request->status)               // scope: 'all' = active+closed, else exact match
    ->createdBetween($request->from, $request->to)
    ->when($request->boolean('has_lead'), fn($q) => $q->whereNotNull('lead_id'))
    ->withCount('messages')
    ->with([
        'latestMessage:id,conversation_id,content,created_at',
        'lead:id,name,email',
    ])
    ->latest('created_at')
    ->paginate(25)
    ->withQueryString();
```

`show` loads `messages` ordered by `created_at` and the linked `lead` (id, name, email, score).

`archive` / `unarchive` flip `status` between `active`/`closed` and `archived`. No state machine — just two distinct verbs that toggle.

`export` streams a `.txt` via `response()->streamDownload(...)`, no temp file. Filename: `conversation-{id}.txt`. Format:

```
Conversation #137
Started: 2026-05-03 10:32:14
Status: closed

[10:32:14] Visitor: Hi, what services do you offer?
[10:32:18] Assistant: We specialize in building websites…
[10:33:01] Visitor: Can I see pricing?
[10:33:05] Assistant: Sure — our Starter plan…
```

## Model changes

`app/Models/Conversation.php`:

- `$fillable` updated to remove the dropped columns
- New `latestMessage()` relation: `hasOne(Message::class)->latestOfMany()`
- New scopes: `scopeForTenant`, `scopeWithStatus`, `scopeCreatedBetween`

`scopeWithStatus` accepts: an explicit status (`active`/`closed`/`archived`) → exact match; `'all'` → no filter (includes archived); `null` or missing → default of "active OR closed", excluding archived. The dropdown's default selection corresponds to the third case.

## Frontend

### Sidebar nav

`resources/js/Layouts/ClientLayout.vue` — one new entry in the `navigation` array, between `Widget` and `Knowledge Base`:

```js
{ name: 'Conversations', href: '/conversations', icon: MessageCircle },
```

### Index page

`resources/js/Pages/Client/Conversations/Index.vue`

Filter strip above the table, query-string driven (`?status=active&from=2026-04-01&has_lead=1`):

- **Status select** — Active+Closed (default) / Active / Closed / Archived / All. Default deliberately excludes archived; the "All" option includes archived.
- **Date range** — two date inputs (`from`, `to`), both optional
- **Has-lead toggle** — Off / On

Table columns:

| When | Last message | Status | Lead | Messages |
|---|---|---|---|---|
| relative time, tooltip absolute | 80-char truncated preview | colored badge | `✓ captured` or `—` | count |

Row click → `/conversations/{id}`. No bulk-select.

Pagination: server-side, 25/page, "Showing 1–25 of 137" footer.

Empty state: centered illustration + "No conversations yet — add the widget to your site to start collecting them." with a link to `/widget-settings`.

### Detail page

`resources/js/Pages/Client/Conversations/Show.vue`

Two-column layout (sidebar collapses to accordion above transcript on `<lg`).

**Center column — transcript:**
- Visitor messages: left-aligned, `bg-muted` bubble
- Assistant messages: right-aligned, `bg-primary/10` bubble
- Timestamp next to each bubble (relative; absolute on hover)
- `whitespace-pre-wrap` for content; rendered as text, not HTML — XSS-safe

**Right sidebar — sticky on `lg:`:**

1. **Metadata card** — created_at, status badge, session_id (monospace, middle-truncated), IP, user-agent
2. **Lead card** — only renders when `conversation.lead_id` is set. Name, email, score with color-coded label (Cold/Warm/Hot per `LeadScoringService` thresholds), "View lead →" link to `/leads/{id}`
3. **Actions card** —
   - **Export transcript** → `GET /conversations/{id}/export`, browser saves `.txt`
   - **Archive** / **Unarchive** (label flips with current status) → `PUT`, with `confirm()` dialog, success toast, redirect back to index. No undo.

## Tests

Three new test files.

**`tests/Feature/Client/ConversationsIndexTest.php`** — ~8 cases:
- Lists tenant's conversations, paginates at 25
- Filters by status / date range / has-lead independently and combined
- 404 on another tenant's conversation in URL
- Empty state renders
- Default status filter excludes archived
- Assertions via `Inertia::assertHasProp('conversations.data')`

**`tests/Feature/Client/ConversationsShowTest.php`** — ~6 cases:
- Shows transcript with messages in correct order
- Shows lead card when linked, hides when not
- 404s on cross-tenant access
- Archive / unarchive flip status correctly
- Export streams `.txt` with filename `conversation-{id}.txt` and `text/plain` Content-Type
- Export contains visitor + assistant lines in chronological order

**`tests/Unit/Models/ConversationTest.php`** — ~3 cases:
- `forTenant`, `withStatus`, `createdBetween` scopes return expected ids
- `latestMessage` relation returns the most recent message (regression: not the first)
- Default index (no status param) excludes archived; `?status=all` includes archived; `?status=archived` returns only archived

**Factories:** `database/factories/ConversationFactory.php` and `MessageFactory.php` are absent today; both will be added since the new tests need readable setup.

## Verification after build

- `php artisan test` — all green
- Browser walk:
  - `/conversations` renders, filters narrow results, pagination works
  - Click a row → detail page shows transcript + sidebar
  - Lead card links to `/leads/{id}`
  - Export downloads a `.txt` with correct content
  - Archive flips status, conversation disappears from default list, reappears under Archived filter, unarchive restores it

## File inventory

**New:**
- `app/Http/Controllers/Client/ConversationController.php`
- `database/factories/ConversationFactory.php`
- `database/factories/MessageFactory.php`
- `resources/js/Pages/Client/Conversations/Index.vue`
- `resources/js/Pages/Client/Conversations/Show.vue`
- `tests/Feature/Client/ConversationsIndexTest.php`
- `tests/Feature/Client/ConversationsShowTest.php`
- `tests/Unit/Models/ConversationTest.php`

**Modified:**
- `routes/web.php` — five new routes inside the `auth` group
- `app/Models/Conversation.php` — scopes, `latestMessage` relation, `HasFactory` trait (`$fillable` already matches the real schema, no change needed)
- `app/Models/Message.php` — `HasFactory` trait
- `resources/js/Layouts/ClientLayout.vue` — one navigation entry
- `ROADMAP.md` — flip M8.3 from `❌ not built` to `✅`; correct the M2.5 schema example separately

## Change log

- **2026-05-03** — During implementation, discovered the `conversations` table never had the four columns (`visitor_id`, `started_at`, `ended_at`, `lead_score`) the spec proposed dropping. The actual `create_conversations_table` migration only ever created `id, tenant_id, lead_id, session_id, status, metadata, timestamps`. The earlier "verification" via `$conversation->visitor_id` was misled by Eloquent's `__get` returning `null` for unknown attributes. Plan Task 1 (the migration) was deleted; Approach 2 collapsed to Approach 1 in practice. ROADMAP M2.5's schema example is being corrected separately.
