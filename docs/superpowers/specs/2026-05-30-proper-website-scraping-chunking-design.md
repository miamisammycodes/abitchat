# Proper Website Scraping & Chunking — Design Spec

- **Date:** 2026-05-30
- **Branch:** `feat/scraping-clean-extraction`
- **Status:** Approved (design) — pending spec review → implementation plan
- **Author:** Sameer + Claude

## Problem

A tenant's crawled site (`bookbhutantour.com`) is a **client-side-rendered SPA** (base44/React). A plain HTTP GET returns only the pre-render shell: `<head>` meta/OG tags, a `<title>`, ~8 `<script>` tags (the JS bundle), and an empty `<div id="root">`. The actual content is painted by JavaScript in the browser, which the crawler never runs.

Confirmed against live data (read-only probes, since removed):

- All 10 crawled pages produced **one near-identical useless chunk** each, e.g. `"Tours | Book Bhutan Tour Tours on Book Bhutan Tour. Official Website for Book Bhutan Tour."` — SEO boilerplate, **not** real content. The bot effectively knows nothing about the tours.
- **0** chunks anywhere contain `<`, `http`, or `function` — so the "bot replies with code" symptom is **not** code leaking into chunks today; it is the dashboard rendering the raw HTML `content` column (`KnowledgeBaseController.php:153`), which looks like "the entire code is scraped." (If a genuine code-in-reply example surfaces, treat as a separate bug.)

Two secondary defects found by reproduction:

1. **Block elements merge without whitespace.** `extractTextFromHtml` uses `textContent`, which concatenates with no separator: `<h1>Our Bakery</h1><p>We bake…` → `"Our BakeryWe bake…"`. Corrupts every page's text and hurts retrieval. (`DocumentProcessor.php:163`)
2. **The "empty page" guard is fooled by SPAs.** `SiteCrawler` checks `strip_tags($body) === ''`, but `strip_tags` leaves *script text*, so a JS shell looks non-empty and is indexed as a junk-but-`Ready` item. (`SiteCrawler.php:130`)

## Goal

Make scraping & chunking produce clean, useful text — and when a page yields no real content, **detect it, exclude it from RAG, and tell the merchant** — instead of silently indexing boilerplate. The genuine fix for SPA sites (JavaScript rendering) is **Phase 2**.

## Decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| D1 | Phasing | PR1 = clean extraction + SPA detection (HTTP-only); PR2 = headless render-on-fallback | Stop indexing garbage now; sequence the infra-heavy fix after |
| D2 | Content storage | Replace `content` with clean extracted text (no raw HTML kept) | Fixes "I see code"; clean text is far smaller than raw HTML; re-extract via re-crawl |
| D3 | No-content UX | Dedicated `SkippedNoContent` status + merchant guidance | Not an error; actionable; auto-excluded from RAG |
| D4 | PR2 rendering | Render only on fallback (HTTP first, Chromium when gate fails) | WordPress is server-rendered; only SPA pages pay the Chromium cost |
| D5 | Render library (PR2) | self-hosted `spatie/browsershot` | Pre-prod, no infra constraint; $0 marginal (serialized crawls, local embeddings) |
| D6 | Empty detection | **Combined** signal: low word count **AND** SPA-shell marker (not a plain threshold) | A plain threshold buries thin-but-real pages (a contact page is ~16 words, same as base44 boilerplate) |
| D7 | `status` column | Migrate DB enum → `string`, PHP enum cast is the validator | The current `enum()` rejects the new value on Postgres (dev/prod) **and** SQLite (test); string matches `Transaction`/`Lead` and ends the per-status migration tax |
| D8 | Extraction location | Crawler extracts synchronously (crawl path); job extracts (manual paths) | Under a real queue the crawl finalizes before jobs run — synchronous extraction keeps crawl stats honest |

## The content-sufficiency gate (D6)

A shared helper used by both the crawler and the job.

```
wordCount = count(preg_split('/\s+/', trim(cleanText)))   // cleaned text, no stopword filtering — predictable

skip when:
    cleanText === ''                                       // truly empty
  OR wordCount < HARD_FLOOR_WORDS        (≈ 3)             // near-empty, any type
  OR (wordCount < SPA_CEILING_WORDS (≈ 25)
       AND rawHtmlHasSpaShellMarker(html))                 // short AND looks like an unrendered SPA
```

`rawHtmlHasSpaShellMarker(html)` is true when **either**:
- an empty app-mount element is present — `<div id="root">`, `id="app"`, `id="__next"`, `id="__nuxt"` with empty/whitespace inner content, **or**
- `<script>` content bytes dominate the page (script-bytes / total-bytes above a configured ratio, e.g. `0.6`).

Constants live in config (named, tunable). SPA markers require raw HTML; `faq`/`text`/`document` (no HTML) use only the empty/`HARD_FLOOR` rules.

**Why combined:** `bookbhutantour.com` boilerplate is ~16 words **and** has an empty `#root` → skipped. A legitimate 16-word contact page has **no** SPA marker → indexed normally. A plain `< 25` threshold would wrongly bury the contact page permanently (Phase 2 rendering can't lengthen a genuinely short server-rendered page).

## Architecture & data flow

### `DocumentProcessor` (refactor)
- `extractHtml(string $html): string` — **public**; cleaned-text extraction with the block-separator fix.
- `extract(KnowledgeItem $item): string` — **public**; type-switch returning clean text. For `webpage`: return `content` as-is when already populated (crawler pre-cleaned it), else fetch `source_url` + clean. For `document`: read file + clean. For `faq`/`text`: return `content`.
- `chunk(string $text): array` — **public** (was private); chunking (block-separator fix means cleaner chunks).
- Remove `process()` (clean break — see test-migration in file map).

### Block-separator fix (in `extractHtml`)
After removing junk tags and before reading `textContent`: for each block-level element (`p, div, h1–h6, li, br, section, article, blockquote, pre, tr, td, th, ul, ol, table, main`), append a `\n` `DOMText` node so `textContent` separates blocks. Existing `cleanText()` collapses runs.

### `SiteCrawler` (crawl webpage path — extracts synchronously, D8)
1. fetch body (raw HTML).
2. `cleanText = processor.extractHtml(body)`.
3. `content_hash` change-detection unchanged (hash of fetched body in `metadata`, never the stored column — verified `SiteCrawler.php:140,168`).
4. gate `isSufficient(cleanText, body)`:
   - **insufficient** → upsert item `status = SkippedNoContent`, `content = cleanText`, `metadata.skipped_reason = 'no_content'`, `metadata.skipped_at`; increment new `pages_skipped_no_content` session counter; **do not dispatch**.
   - **sufficient** → upsert item `status = Pending`, `content = cleanText`; dispatch `ProcessKnowledgeItem`; increment `pages_indexed`.
5. Remove the `strip_tags` guard and the now-dead `emptyExtractCount` branch. Update the finalize `match` (`SiteCrawler.php:201-207`) so an all-skipped crawl reports `Partial`, not `Completed`.

### `ProcessKnowledgeItem` (manual paths + crawl-webpage chunking)
1. `markProcessing`.
2. `cleanText = processor.extract(item)`.
3. gate. **Insufficient** OR `chunk()` returns `[]` → `item.chunks()->delete()` (clear stale chunks), `markSkippedNoContent`, set `metadata.skipped_*`, **return** (no throw → no 3× retry storm).
4. store `content = cleanText` for `webpage`/`document`; leave `faq`/`text`.
5. `chunks = processor.chunk(cleanText)`; atomic delete+insert; dispatch `GenerateEmbeddings` (unchanged).

> Note: crawl-origin webpage items are gated twice (crawler + job). Idempotent and cheap; keeps the job self-contained for manual items.

### State machine (`KnowledgeItemWorkflow` + `KnowledgeItemStatus`)
- New case `SkippedNoContent = 'skipped_no_content'`.
- `markSkippedNoContent(item)`: `Processing → SkippedNoContent`.
- **No `retry()` change** (Phase-1 retry would be an unreachable no-op; the `metadata.skipped` marker lets the Phase-2 sweep find these items).
- `markReady`/`markFailed` source guards unchanged. (`markFailed` could legally clobber `SkippedNoContent`, but is unreachable in Phase 1 since the skip path returns success — revisit in Phase 2.)

### Retrieval
No change — `RetrievalService` already filters `status = Ready` (`RetrievalService.php:62,105`), so `SkippedNoContent` is auto-excluded.

### Schema (D7)
Migration: convert `knowledge_items.status` from DB `enum(...)` to a plain `string`. Keep the `['tenant_id','status']` index. **Engine note:** the dev/prod DB is **Postgres**, where Laravel's `enum()` compiles to `varchar` + a `CHECK (status in (...))` constraint; the test DB is **SQLite** (same CHECK form). The migration must **drop/replace that CHECK constraint** portably — driver-aware (Postgres `ALTER TABLE ... DROP CONSTRAINT IF EXISTS knowledge_items_status_check`) and/or a Laravel `->change()` table rebuild for SQLite. **Task 0 must run the migration on SQLite and confirm a `skipped_no_content` row inserts.** PHP enum cast (`KnowledgeItem.php:47`) remains the validator.

### Dashboard (`Index.vue` **and** `Show.vue`)
- Add `skipped_no_content: 'warning'` to **both** `getStatusVariant` maps.
- Add a status→label map so the badge shows "Skipped — no content", not the raw `skipped_no_content` value (Inertia serializes the enum to its `.value`).
- Add a guidance row (`v-if status === 'skipped_no_content'`): *"This page is rendered by JavaScript — no readable text was found. Add its content manually, or wait for site-rendering support."* Copy hardcoded in the Vue (not `error_message`).
- No retry button for skipped items. Note `document` content is file-derived (treat as read-only in `edit()`); minor, may defer the field-disable.

## File map

**Create**
- `database/migrations/2026_05_30_*_change_knowledge_items_status_to_string.php`
- content-sufficiency gate helper (e.g. `app/Services/Knowledge/ContentSufficiency.php`)
- tests (see Test plan)

**Modify**
- `app/Enums/KnowledgeItemStatus.php` — add `SkippedNoContent`
- `app/Services/Knowledge/DocumentProcessor.php` — split `process()` → `extractHtml`/`extract`/`chunk` (public), block-separator fix
- `app/Services/Knowledge/KnowledgeItemWorkflow.php` — `markSkippedNoContent`
- `app/Services/Crawler/SiteCrawler.php` — synchronous extract + gate, skip counter, finalize `match`, remove dead guard/branch
- `app/Jobs/ProcessKnowledgeItem.php` — gate, skip path, stale-chunk delete, store clean text
- `app/Models/CrawlSession.php` + crawl-sessions migration — `pages_skipped_no_content` column
- `config/*` — gate constants (`HARD_FLOOR_WORDS`, `SPA_CEILING_WORDS`, script-ratio)
- `resources/js/Pages/Client/KnowledgeBase/Index.vue` — badge variant + label + guidance row
- `resources/js/Pages/Client/KnowledgeBase/Show.vue` — same badge variant + label + guidance
- (maybe) `app/Http/Controllers/Client/KnowledgeBaseController.php` — if guidance/label supplied server-side

**Test migration (clean break of `process()`)**
- `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php` (8 `->process(` calls) → `chunk(extract(...))`
- `tests/Unit/Services/DocumentProcessorFetchTest.php` (3 calls)
- `tests/Unit/Services/DocumentProcessorDocxTest.php` (2 calls)
- `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` — Mockery `shouldReceive('process')` → `'extract'` (+ real/stub `chunk`)
- `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` — verify fixtures stay above the gate

## Task 0 (verification before code)
- `grep -rn '->process(' tests app` — enumerate every `DocumentProcessor::process` call site.
- Check every processor/job **test fixture** word count against the gate; with the combined gate (plain-text fixtures have no SPA marker) the existing 13/15/16-word fixtures should **not** skip — confirm, don't assume.
- Confirm the `knowledge_items.status` column definition and that the migration is portable to SQLite (test DB).

## Test plan (TDD)
- `extractHtml`: `<h1>` + `<p>` separated by whitespace (no `"BakeryWe"` merge); list items separated.
- Gate: SPA shell (empty `#root`, ~16 words) → skip; 16-word plain page (no marker) → sufficient; empty text → skip; script-dominated page → skip.
- `ProcessKnowledgeItem`: real page → `Ready` + `content` overwritten with clean text + chunks created; SPA → `SkippedNoContent`, **not** `Failed`, no `GenerateEmbeddings`, no retry; `chunk()`-empty-after-gate → skip; Ready→Skip re-crawl deletes stale chunks.
- `SiteCrawler`: SPA page → item ends `SkippedNoContent`, `pages_skipped_no_content` incremented, not dispatched; all-skipped crawl → session `Partial`.
- `KnowledgeItemWorkflow`: `Processing → SkippedNoContent` allowed; illegal sources rejected.
- Layer 2: full `php artisan test` between tasks. Layer 3: browser smoke — crawl a SPA, see `SkippedNoContent` + guidance in the dashboard.

## Phase 2 (documented, not built here)
`spatie/browsershot` (Node + Chromium); `PageRenderer.render(url): ?string` with HTTP fallback; config `CRAWLER_JS_RENDERING`. Render-on-fallback: when the gate fails on HTTP, render + re-extract before skipping. Re-process previously-`SkippedNoContent` items (located via `metadata.skipped_reason`), bypassing the `content_hash` skip once. Revisit `markFailed` forbidding `SkippedNoContent` as a source.

## Out of scope
Auth-walled pages; infinite-scroll / pagination; per-tenant rendering toggle; cross-page content dedup; routing `reprocess()` through the workflow (pre-existing raw-status override); `MEDIUMTEXT` bump for `content` (clean text is far smaller than the raw HTML previously stored).
