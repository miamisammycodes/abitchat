# Phase 2 — Headless Render-on-Fallback — Design Spec

- **Date:** 2026-05-30
- **Branch:** `feat/phase2-headless-render` (stacked on `feat/scraping-clean-extraction` / PR #41 — merge after #41)
- **Status:** Approved (design) — pending spec review → implementation plan
- **Builds on:** Phase 1 (`docs/superpowers/specs/2026-05-30-proper-website-scraping-chunking-design.md`)

## Problem

Phase 1 detects JavaScript-rendered (SPA) pages and marks them `SkippedNoContent` so the bot isn't fed boilerplate — but it can't *extract* content that only exists after JS runs. For the actual customer site (`bookbhutantour.com`, base44/React) every page is skipped, so the bot still knows nothing. Phase 2 renders such pages with a headless browser so they yield real content.

## Goal

When a page fails the content-sufficiency gate on its raw HTTP body, render it with a headless browser and re-extract before giving up. Server-rendered pages (the WordPress majority) never pay the rendering cost — rendering is a **fallback**, gated behind an opt-in flag, and degrades gracefully when unavailable.

## Decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| P2-1 | Re-process existing `SkippedNoContent` items | **Auto on next crawl** — bypass the content-hash dedup for `SkippedNoContent` items, **but only when rendering is enabled** | No new surface; existing SPA pages heal on the next crawl. Flag-gating avoids re-fetch churn when rendering is off |
| P2-2 | Scope | **Crawler + manual single-URL add**, via a shared service | Fully closes the Phase-1 manual-add gap; identical behavior in both paths |
| P2-3 | Renderer | self-hosted `spatie/browsershot` (Puppeteer + Chromium) | Phase-1 D5; pre-prod, $0 marginal |
| P2-4 | Flag default | `CRAWLER_JS_RENDERING=false` (opt-in) | Needs Chromium installed; dormant until the operator enables it. Phase-1 behavior everywhere until then |
| P2-5 | Failure behavior | **Graceful degradation** — render failure / timeout / missing Chromium → `null` → fall back to the HTTP result (page stays `SkippedNoContent`), logged; crawl never crashes | A flaky renderer must never break a crawl |
| P2-6 | Helper shape | A distinct **`RenderOnFallback`** service (not inlined per caller) | One behavior, two callers; testable in isolation |

## Architecture & data flow

### `PageRenderer` (`app/Services/Crawler/PageRenderer.php`)
- `render(string $url): ?string` — rendered HTML or `null`.
- Returns `null` immediately when `config('services.crawler.js_rendering')` is false (no Browsershot call).
- Re-validates `SafeExternalUrl::isSafe($url)` before rendering (SSRF guard — mirrors the fetch path).
- `Browsershot::url($url)->waitUntilNetworkIdle()->timeout($seconds)->bodyHtml()` (timeout from `config('services.crawler.render_timeout')`, default 15s). Optional `setNodeBinary`/`setChromePath` from config when set.
- Catches **all** throwables → logs (`[PageRenderer] (IS $) …`) → returns `null`. Never throws.
- Injected (constructor) so tests bind a fake/mock — the codebase already mocks `EmbeddingService`/`DocumentProcessor`. The **real Browsershot path is not unit-tested** (CI has no Chromium); it is verified by Task 0 + the live smoke.

### `RenderOnFallback` (`app/Services/Crawler/RenderOnFallback.php`)
Composes `DocumentProcessor` + `ContentSufficiency` + `PageRenderer`.

```
resolve(string $url, string $httpBody): array{text:string, html:string, sufficient:bool, rendered:bool}

  $text = $processor->extractHtml($httpBody);
  if ($gate->isSufficient($text, $httpBody)) {
      return ['text'=>$text, 'html'=>$httpBody, 'sufficient'=>true, 'rendered'=>false];
  }
  $rendered = $renderer->render($url);            // null when off / failed
  if ($rendered !== null) {
      $rText = $processor->extractHtml($rendered);
      if ($gate->isSufficient($rText, $rendered)) {
          return ['text'=>$rText, 'html'=>$rendered, 'sufficient'=>true, 'rendered'=>true];
      }
  }
  return ['text'=>$text, 'html'=>$httpBody, 'sufficient'=>false, 'rendered'=>($rendered !== null)];
```

### `SiteCrawler` wiring
- Per page: replace the inline `extractHtml` + `isSufficient` with `RenderOnFallback::resolve($url, $body)`.
  - `sufficient` → index: store `result['text']` as `content`, `status = Pending`, dispatch `ProcessKnowledgeItem`, `pages_indexed++`.
  - not `sufficient` → `SkippedNoContent` (+ delete stale chunks, as Phase 1), `pages_skipped_no_content++`.
- **Hash-skip change** (P2-1): the existing content-hash dedup skips only when the existing item is **not** a heal candidate. A `SkippedNoContent` item is re-attempted (skip the dedup) **only when `PageRenderer` is enabled**; otherwise Phase-1 behavior (hash-skip applies) is unchanged. Encapsulate the "is rendering enabled" check on `PageRenderer` (e.g. `PageRenderer::enabled(): bool`) so the crawler doesn't read config directly.

### `ProcessKnowledgeItem` wiring (manual add)
- The `webpage`-with-`source_url` path: fetch the body (SSRF-guarded, `allow_redirects=false` — as today) then `RenderOnFallback::resolve($url, $body)`. Sufficient → store `result['text']` as `content`, chunk, embed. Not sufficient → `SkippedNoContent` (Phase-1 skip path). `webpage`-with-stored-`content` and `faq`/`text`/`document` paths are unchanged.

### Config (`config/services.php`)
```php
'crawler' => [
    'js_rendering' => env('CRAWLER_JS_RENDERING', false),
    'render_timeout' => (int) env('CRAWLER_RENDER_TIMEOUT', 15),
    'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
    'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
],
```

## Task 0 (verification before code — CRITICAL)
External-dependency behavior; must pass before Task 1.
- `composer require spatie/browsershot` + `pnpm add puppeteer` (downloads Chromium).
- Spike: render `https://bookbhutantour.com/` with `Browsershot::url(...)->waitUntilNetworkIdle()->bodyHtml()` and confirm (a) it returns HTML with the `#root` **populated** (real tour text present), and (b) `ContentSufficiency::isSufficient(extractHtml(rendered), rendered)` is now **true**. Capture the working wait/timeout settings.
- If Browsershot cannot run in this environment (no Node/Chromium), STOP and report — the wait strategy / binary paths feed the `PageRenderer` impl and the plan.

## Test plan (TDD)
- **`PageRenderer`**: flag off → `render()` returns `null` without invoking Browsershot; unsafe URL → `null`. (Real render covered by Task 0 / smoke.)
- **`RenderOnFallback`** (real `DocumentProcessor` + `ContentSufficiency`, **fake `PageRenderer`**):
  - HTTP body already sufficient → `rendered=false`, no renderer call.
  - HTTP insufficient + fake renderer returns sufficient HTML → `sufficient=true, rendered=true`, text from rendered.
  - HTTP insufficient + renderer returns `null` (disabled/failed) → `sufficient=false, rendered=false`.
  - HTTP insufficient + rendered still insufficient → `sufficient=false, rendered=true`.
- **`SiteCrawler`** (fake `PageRenderer`):
  - rendering enabled + SPA page that the fake "renders" into real content → item ends `Ready`/`Pending` + dispatched, `pages_indexed` counts it.
  - rendering enabled + existing `SkippedNoContent` item, unchanged hash → **re-attempted** (not hash-skipped).
  - rendering disabled → Phase-1 behavior intact (SPA → `SkippedNoContent`, unchanged-hash skipped). (Existing Phase-1 crawler tests must stay green.)
- **`ProcessKnowledgeItem`** (fake `PageRenderer`): manual SPA URL add → renders → indexed; renderer null → `SkippedNoContent`.
- Layer 2: full `php artisan test` between tasks. Layer 3: live smoke — install Chromium, `CRAWLER_JS_RENDERING=true`, real crawl of `bookbhutantour.com` → pages now `Ready` with real chunks; browser-view the dashboard.

## Out of scope
Per-tenant rendering toggle; rendering for `document`/PDF types; screenshots / visual capture; render-concurrency throttling across the queue; the pre-existing crawl redirect-SSRF hardening (separate PR); auth-walled / login-required pages.

## Deploy steps (after merge)
1. Install Chromium on the host (`pnpm exec puppeteer browsers install chrome`, or system Chrome + `BROWSERSHOT_CHROME_PATH`).
2. Set `CRAWLER_JS_RENDERING=true` (+ `BROWSERSHOT_NODE_BINARY`/`CHROME_PATH` if non-standard).
3. Trigger a re-crawl per tenant with a SPA site — previously-`SkippedNoContent` pages heal automatically (P2-1).
