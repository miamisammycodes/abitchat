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
| P2-7 | SSRF posture | **Document + hard deploy-gate + mandatory follow-up PR** (no in-code egress filter in this phase) | Rendering is a new SSRF surface (see Security); flag is off by default and pre-prod, so we ship behind an explicit gate and a named hardening follow-up that BLOCKS prod enablement |
| P2-8 | Heal-loop guard | Re-render a `SkippedNoContent` item only if it has **no `metadata.render_attempted_at`** (or its content hash changed) | Without this, the unrenderable tail (auth-walled / genuinely empty pages) re-renders ~15s each on every crawl forever |

## Architecture & data flow

### `PageRenderer` (`app/Services/Crawler/PageRenderer.php`)
- `render(string $url): ?string` — rendered HTML or `null`.
- Returns `null` immediately when `config('services.crawler.js_rendering')` is false (no Browsershot call).
- Re-validates `SafeExternalUrl::isSafe($url)` before rendering (SSRF guard — mirrors the fetch path).
- `Browsershot::url($url)->setNodeModulePath(base_path('node_modules'))->setDelay($delay)->timeout($seconds)->bodyHtml()` — Task 0 verified that base44/React SPAs never reach network idle (so `waitUntilNetworkIdle()` always times out); a fixed post-load `setDelay` (ms, from `config('services.crawler.render_delay')`, default 3000) is used instead. Timeout from `config('services.crawler.render_timeout')`, default 45s. `setNodeModulePath(base_path('node_modules'))` is unconditional (pnpm's non-hoisted layout breaks puppeteer module resolution). `setNodeBinary`/`setNpmBinary`/`setChromePath` applied from config when set — **`chrome_path` is effectively mandatory on macOS + pnpm** (puppeteer can't auto-resolve the downloaded "Chrome for Testing.app").
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
- **Hash-skip change** (P2-1 + P2-8): the content-hash dedup skips unless the item is a *heal candidate*. An item is a heal candidate when `renderingEnabled()` **and** `status === SkippedNoContent` **and** it has no `metadata.render_attempted_at` (P2-8 — so a page that was already render-attempted and is still insufficient is NOT re-rendered every crawl; a content-hash change still re-processes it normally). When insufficient *and* rendering was enabled, the skip path stamps `metadata.render_attempted_at`. With rendering off, behavior is exactly Phase-1. Encapsulate the enabled check on `PageRenderer` (`enabled(): bool`, surfaced via `RenderOnFallback::renderingEnabled()`).

### `ProcessKnowledgeItem` wiring (manual add)
- The `webpage`-with-`source_url` path: fetch the body (SSRF-guarded, `allow_redirects=false` — as today) then `RenderOnFallback::resolve($url, $body)`. Sufficient → store `result['text']` as `content`, chunk, embed. Not sufficient → `SkippedNoContent` (Phase-1 skip path). `webpage`-with-stored-`content` and `faq`/`text`/`document` paths are unchanged.

### Config (`config/services.php`)
```php
'crawler' => [
    'js_rendering' => env('CRAWLER_JS_RENDERING', false),
    'render_timeout' => (int) env('CRAWLER_RENDER_TIMEOUT', 45),
    'render_delay' => (int) env('CRAWLER_RENDER_DELAY', 3000),
    'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
    'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
    'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
],
```

## Task 0 (verification before code — CRITICAL)
External-dependency behavior; must pass before Task 1.
- `composer require spatie/browsershot` + `pnpm add puppeteer` (downloads Chromium).
- Spike: render `https://bookbhutantour.com/` and confirm (a) it returns HTML with the `#root` **populated** (real tour text present), and (b) `ContentSufficiency::isSufficient(extractHtml(rendered), rendered)` is now **true**. Capture the working wait/timeout settings.
- **VERIFIED 2026-05-30:** `waitUntilNetworkIdle()` always timed out (base44 SPA never idles). Working chain: `Browsershot::url($url)->setNodeBinary($herdNode)->setNpmBinary($herdNpm)->setNodeModulePath(base_path('node_modules'))->setChromePath($puppeteerChromeForTesting)->setDelay(3000)->timeout(45)->bodyHtml()`. Result across 3 runs: 66–83KB HTML, 372–1263 words, `sufficient=YES`, real tour copy ("Bhutan's Premier Destination Specialist…"). Render time is highly variable (~7–30s), hence the 45s timeout default. `chrome_path` is mandatory (puppeteer auto-resolve fails on the "Chrome for Testing.app" bundle under pnpm).
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

## Security — headless-render SSRF (NEW vector introduced by this phase)

A headless browser rendering a **tenant-controlled URL** is a server-side request forgery surface that is broader than the HTTP-fetch path:
- Chromium **follows redirects** to any host (incl. `169.254.169.254` metadata, `localhost`, internal IPs) — `SafeExternalUrl::isSafe()` only validates the *initial* URL, never the hops.
- The page's **JavaScript executes** and can `fetch()`/XHR internal or cloud-metadata endpoints; whatever it renders into the DOM is returned by `bodyHtml()`, chunked, embedded, and can surface in bot replies (data exfiltration).

This is **distinct from and worse than** the pre-existing crawl redirect-SSRF, and is *introduced* by Phase 2 — not pre-existing.

**Chosen posture (P2-7):** ship behind mitigations, do not block the feature:
1. `CRAWLER_JS_RENDERING` defaults **off**; `PageRenderer` still runs `SafeExternalUrl::isSafe()` on the initial URL (blocks the obvious case).
2. **Hard deploy gate** (below): only enable on a host with **no reachable internal/metadata endpoints**.
3. **Mandatory follow-up hardening PR** (BLOCKS production enablement): egress filtering for the render path — a Puppeteer request-interception script (or filtering proxy / Chromium `--proxy-server`) that blocks requests and redirects to private + link-local ranges (`127/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16`, `::1`, `fc00::/7`). Bundle the pre-existing crawl redirect-SSRF fix into the same PR (same egress concern).

## Out of scope (this phase)
Per-tenant rendering toggle; rendering for `document`/PDF types; screenshots / visual capture; render-concurrency throttling across the queue; auth-walled / login-required pages. **Render-path egress filtering (the SSRF mitigation above) is a named, mandatory-before-prod follow-up PR — not part of this phase's code.**

## Final-review clarifications (2026-05-30)
- **P2-8 stamp semantics (corrected):** `render_attempted_at` is stamped only when a render **actually executed and returned HTML** (`resolve()`'s `rendered === true`), on both the sufficient and insufficient paths — NOT merely when rendering is enabled. A render that returns `null` (Chromium misconfigured / timed out) is intentionally NOT recorded, so the page stays a heal candidate and retries after the operator fixes Chromium (avoids one bad first crawl poisoning every page).
- **Manual-add parity (P2-2 wins over the literal P2-4 wording):** SPA *detection* (the 2-arg sufficiency gate) now applies to the manual single-URL add path too, **regardless of the flag** — a manually-added SPA shell is marked `SkippedNoContent` even with rendering off, instead of indexing boilerplate as Phase-1 did. Only *rendering* (the heal) is flag-gated, not detection. This is the intended unification; the earlier "flag off = zero behavior change" framing was imprecise — flag off = Phase-1-correct behavior **plus** consistent SPA detection on both paths.
- **Known edge for the pre-prod-enablement follow-up:** a page the gate deems sufficient but that yields zero ≥50-char chunks in `ProcessKnowledgeItem` is marked `SkippedNoContent` without a render attempt, so with rendering on it remains a heal candidate and is re-fetched/re-dispatched (not re-rendered) each crawl. Narrow; fix alongside the SSRF/egress follow-up (e.g. a distinct skip reason that is not heal-eligible).

## Deploy steps (after merge)
1. **⚠️ SSRF gate (P2-7):** do NOT set `CRAWLER_JS_RENDERING=true` until the render-path egress-filtering follow-up PR has shipped **and** the host has no reachable internal/cloud-metadata endpoints. Until then, leave rendering off.
2. Install Chromium on the host (`pnpm exec puppeteer browsers install chrome`, or system Chrome + `BROWSERSHOT_CHROME_PATH`).
3. Set `CRAWLER_JS_RENDERING=true` (+ `BROWSERSHOT_NODE_BINARY`/`CHROME_PATH` if non-standard) — only after step 1's gate is satisfied.
4. Trigger a re-crawl per tenant with a SPA site — previously-`SkippedNoContent` pages heal automatically (P2-1).
