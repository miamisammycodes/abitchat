# Phase 2 — Headless Render-on-Fallback — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a crawled or manually-added page fails the content-sufficiency gate on its raw HTTP body, render it with a headless browser (Browsershot) and re-extract — so JavaScript-rendered (SPA) sites yield real content instead of being skipped.

**Architecture:** A flag-gated `PageRenderer` (Browsershot wrapper, graceful, SSRF-guarded) + a shared `RenderOnFallback` service (`DocumentProcessor` + `ContentSufficiency` + `PageRenderer`) that both `SiteCrawler` and `ProcessKnowledgeItem` call. Rendering is opt-in (`CRAWLER_JS_RENDERING`, default off) and degrades to Phase-1 behavior when disabled or Chromium is unavailable. Existing `SkippedNoContent` pages auto-heal on the next crawl when rendering is on.

**Tech Stack:** Laravel 13 / PHP 8.3, `spatie/browsershot` + Puppeteer/Chromium, Node v22 (Herd), Postgres (dev) / SQLite (tests).

**Spec:** `docs/superpowers/specs/2026-05-30-phase2-headless-render-fallback-design.md`
**Branch:** `feat/phase2-headless-render` (stacked on `feat/scraping-clean-extraction` / PR #41 — merge after #41).

**Environment (already verified):** Node `v22.22.3` at `~/Library/Application Support/Herd/config/nvm/versions/node/v22.22.3/bin/node`; Google Chrome at `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`. `js_rendering` defaults **false**, so the whole test suite runs the Phase-1 path unless a test injects a fake renderer.

---

## File structure

- **Create:** `app/Services/Crawler/PageRenderer.php`, `app/Services/Crawler/RenderOnFallback.php`, their tests, and `tests/Feature/Crawler/RenderOnFallbackCrawlTest.php`.
- **Modify:** `config/services.php` (+`crawler` block), `.env.example`, `app/Services/Knowledge/DocumentProcessor.php` (+`fetchUrl`), `app/Services/Crawler/SiteCrawler.php` (swap deps → `RenderOnFallback`, heal-bypass), `app/Jobs/ProcessKnowledgeItem.php` (manual-add render-on-fallback), and the two job test files.

---

## Task 0: Install Browsershot + verify it renders the live SPA (CRITICAL — before any other task)

**Files:** `composer.json`, `composer.lock`, `package.json` (via tooling), throwaway spike (not committed).

- [ ] **Step 0: Isolate the pre-existing dependency bump (do this FIRST)**

The working tree already has an unrelated `composer.lock` bump (~30 packages, including majors like `v2.0.22→v3.1.0`) that predates this branch. Commit it on its own BEFORE adding Browsershot so the dependency change isn't entangled:
```bash
composer install            # sync vendor/ to the already-bumped lock
php artisan test            # confirm the suite is still green on the bumped deps
git add composer.json composer.lock
git commit -m "chore(deps): bump dependencies"
```
Expected: suite green; a standalone deps commit. If the suite is RED on the bumped deps, STOP and report (the bump is the user's separate concern — do not proceed onto a broken base).

- [ ] **Step 1: Install dependencies**

Run:
```bash
composer require spatie/browsershot
pnpm add puppeteer
pnpm exec puppeteer browsers install chrome
```
Expected: Browsershot in `vendor/`, puppeteer in `node_modules/`, a Chromium downloaded.

- [ ] **Step 2: Spike — render `bookbhutantour.com` and confirm the gate now passes**

Create `tmp/render_spike.php` and run `php artisan tinker tmp/render_spike.php`:
```php
<?php
use App\Services\Knowledge\{DocumentProcessor, ContentSufficiency};
use Spatie\Browsershot\Browsershot;

$node = '/Users/sam/Library/Application Support/Herd/config/nvm/versions/node/v22.22.3/bin/node';
$npm  = '/Users/sam/Library/Application Support/Herd/config/nvm/versions/node/v22.22.3/bin/npm';

$html = Browsershot::url('https://bookbhutantour.com/')
    ->setNodeBinary($node)
    ->setNpmBinary($npm)
    ->waitUntilNetworkIdle()
    ->timeout(30)
    ->bodyHtml();

$dp = app(DocumentProcessor::class); $gate = app(ContentSufficiency::class);
$text = $dp->extractHtml($html);
echo 'rendered html length: '.strlen($html)."\n";
echo 'extracted words: '.$gate->wordCount($text)."\n";
echo 'sufficient now? '.($gate->isSufficient($text, $html) ? 'YES (render works)' : 'NO')."\n";
echo 'sample: '.str_replace("\n", ' ', mb_substr($text, 0, 200))."\n";
```
Expected: rendered HTML is much larger than the ~6.9KB shell, `sufficient now? YES`, and the sample shows real tour text (not SEO boilerplate).

- [ ] **Step 3: Capture working settings; adjust the plan if needed**

If `waitUntilNetworkIdle()` times out (base44 keeps connections open), try `->setDelay(4000)` (wait 4s after load) or `->waitUntilNetworkIdle($strict = false)`; if it can't find node/Chrome, add `->setChromePath('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')`. **Record the exact working chain** — Task 1's `PageRenderer` and the config defaults must match it. If Browsershot cannot render at all here, STOP and report (the whole phase is blocked).

- [ ] **Step 4: Remove the spike, commit the dependencies**

```bash
rm -f tmp/render_spike.php
git add composer.json composer.lock package.json pnpm-lock.yaml
git commit -m "chore: add spatie/browsershot + puppeteer for headless rendering"
```
> The unrelated dep bump was already committed in Step 0, so this `composer.lock` delta is now only the browsershot addition. Do **not** commit `node_modules/`.

---

## Task 1: `PageRenderer` service + config

**Files:**
- Create: `app/Services/Crawler/PageRenderer.php`
- Modify: `config/services.php`, `.env.example`
- Test: `tests/Unit/Services/Crawler/PageRendererTest.php`

- [ ] **Step 1: Add the config block**

In `config/services.php`, add before the closing `];` (after the `dk_bank` block):
```php
    'crawler' => [
        'js_rendering' => env('CRAWLER_JS_RENDERING', false),
        'render_timeout' => (int) env('CRAWLER_RENDER_TIMEOUT', 15),
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
    ],
```
In `.env.example`, add:
```
CRAWLER_JS_RENDERING=false
# CRAWLER_RENDER_TIMEOUT=15
# BROWSERSHOT_NODE_BINARY=
# BROWSERSHOT_NPM_BINARY=
# BROWSERSHOT_CHROME_PATH=
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Services/Crawler/PageRendererTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\PageRenderer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PageRendererTest extends TestCase
{
    public function test_disabled_by_default_returns_null(): void
    {
        Config::set('services.crawler.js_rendering', false);
        $this->assertFalse(app(PageRenderer::class)->enabled());
        $this->assertNull(app(PageRenderer::class)->render('https://example.com/'));
    }

    public function test_enabled_flag_reflects_config(): void
    {
        Config::set('services.crawler.js_rendering', true);
        $this->assertTrue(app(PageRenderer::class)->enabled());
    }

    public function test_unsafe_url_returns_null_even_when_enabled(): void
    {
        Config::set('services.crawler.js_rendering', true);
        $this->assertNull(app(PageRenderer::class)->render('http://127.0.0.1/admin'));
    }
}
```

- [ ] **Step 3: Run it — expect RED**

Run: `php artisan test --filter=PageRendererTest`
Expected: FAIL — class `PageRenderer` does not exist.

- [ ] **Step 4: Implement `PageRenderer`**

Create `app/Services/Crawler/PageRenderer.php` (use the wait/binary chain confirmed in Task 0):
```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * Renders a URL with a headless browser so JavaScript-painted (SPA) content
 * is captured. Opt-in via services.crawler.js_rendering. Never throws —
 * returns null when disabled, blocked, or on any render failure/timeout, so
 * callers fall back to the raw HTTP body.
 *
 * SECURITY: rendering a tenant-controlled URL is an SSRF surface — Chromium
 * follows redirects and runs page JS that can reach internal/metadata
 * endpoints. The SafeExternalUrl check below guards only the initial URL.
 * Render-path egress filtering (block private/link-local IPs incl. redirect
 * hops) is a mandatory-before-prod follow-up PR; keep CRAWLER_JS_RENDERING off
 * until it ships and the host has no reachable internal endpoints (see spec).
 */
class PageRenderer
{
    public function enabled(): bool
    {
        return (bool) config('services.crawler.js_rendering', false);
    }

    public function render(string $url): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! SafeExternalUrl::isSafe($url)) {
            Log::warning('[PageRenderer] Refusing to render non-public URL', ['url' => $url]);

            return null;
        }

        try {
            Log::debug('[PageRenderer] (IS $) Rendering page', ['url' => $url]);

            $shot = Browsershot::url($url)
                ->waitUntilNetworkIdle()
                ->timeout((int) config('services.crawler.render_timeout', 15));

            if ($node = config('services.crawler.node_binary')) {
                $shot->setNodeBinary((string) $node);
            }
            if ($npm = config('services.crawler.npm_binary')) {
                $shot->setNpmBinary((string) $npm);
            }
            if ($chrome = config('services.crawler.chrome_path')) {
                $shot->setChromePath((string) $chrome);
            }

            return $shot->bodyHtml();
        } catch (\Throwable $e) {
            Log::warning('[PageRenderer] Render failed; falling back to HTTP', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
```

- [ ] **Step 5: Run it — expect GREEN**

Run: `php artisan test --filter=PageRendererTest`
Expected: PASS (all three; the disabled + unsafe paths never invoke Browsershot).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Crawler/PageRenderer.php config/services.php .env.example tests/Unit/Services/Crawler/PageRendererTest.php
git commit -m "feat(crawler): add flag-gated PageRenderer (Browsershot wrapper)"
```

---

## Task 2: `RenderOnFallback` service

**Files:**
- Create: `app/Services/Crawler/RenderOnFallback.php`
- Test: `tests/Unit/Services/Crawler/RenderOnFallbackTest.php`

- [ ] **Step 1: Write the failing test** (real `DocumentProcessor` + `ContentSufficiency`, fake `PageRenderer`)

Create `tests/Unit/Services/Crawler/RenderOnFallbackTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\PageRenderer;
use App\Services\Crawler\RenderOnFallback;
use App\Services\Knowledge\ContentSufficiency;
use App\Services\Knowledge\DocumentProcessor;
use Mockery;
use Tests\TestCase;

class RenderOnFallbackTest extends TestCase
{
    private function resolver(PageRenderer $renderer): RenderOnFallback
    {
        return new RenderOnFallback(new DocumentProcessor, new ContentSufficiency, $renderer);
    }

    private string $spaShell = '<html><body><div id="root">Tours Book Bhutan Tour visit us today right now for more info here</div></body></html>';

    private string $realPage = '<html><body><main><h1>Bhutan Tours</h1><p>We run guided cultural and trekking tours across Bhutan with licensed local guides every season of the year.</p></main></body></html>';

    public function test_sufficient_http_body_does_not_render(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldNotReceive('render');

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->realPage);

        $this->assertTrue($result['sufficient']);
        $this->assertFalse($result['rendered']);
    }

    public function test_insufficient_http_then_render_succeeds(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('render')->once()->with('https://x.test')->andReturn($this->realPage);

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->spaShell);

        $this->assertTrue($result['sufficient']);
        $this->assertTrue($result['rendered']);
        $this->assertStringContainsString('Bhutan', $result['text']);
        $this->assertSame($this->realPage, $result['html']);
    }

    public function test_insufficient_http_and_render_null_is_insufficient(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturn(null);

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->spaShell);

        $this->assertFalse($result['sufficient']);
        $this->assertFalse($result['rendered']);
    }

    public function test_render_still_insufficient(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturn($this->spaShell);

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->spaShell);

        $this->assertFalse($result['sufficient']);
        $this->assertTrue($result['rendered']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=RenderOnFallbackTest`
Expected: FAIL — class `RenderOnFallback` does not exist.

- [ ] **Step 3: Implement `RenderOnFallback`**

Create `app/Services/Crawler/RenderOnFallback.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Services\Knowledge\ContentSufficiency;
use App\Services\Knowledge\DocumentProcessor;

/**
 * Resolves the best clean text for a URL: extract from the raw HTTP body, and
 * if that fails the sufficiency gate, render the page (headless) and re-extract
 * before giving up. Shared by SiteCrawler and ProcessKnowledgeItem.
 */
class RenderOnFallback
{
    public function __construct(
        private DocumentProcessor $processor,
        private ContentSufficiency $gate,
        private PageRenderer $renderer,
    ) {}

    public function renderingEnabled(): bool
    {
        return $this->renderer->enabled();
    }

    /**
     * @return array{text: string, html: string, sufficient: bool, rendered: bool}
     */
    public function resolve(string $url, string $httpBody): array
    {
        $text = $this->processor->extractHtml($httpBody);

        if ($this->gate->isSufficient($text, $httpBody)) {
            return ['text' => $text, 'html' => $httpBody, 'sufficient' => true, 'rendered' => false];
        }

        $rendered = $this->renderer->render($url);

        if ($rendered !== null) {
            $renderedText = $this->processor->extractHtml($rendered);

            if ($this->gate->isSufficient($renderedText, $rendered)) {
                return ['text' => $renderedText, 'html' => $rendered, 'sufficient' => true, 'rendered' => true];
            }
        }

        return ['text' => $text, 'html' => $httpBody, 'sufficient' => false, 'rendered' => $rendered !== null];
    }
}
```

- [ ] **Step 4: Run it — expect GREEN**

Run: `php artisan test --filter=RenderOnFallbackTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Crawler/RenderOnFallback.php tests/Unit/Services/Crawler/RenderOnFallbackTest.php
git commit -m "feat(crawler): add RenderOnFallback (extract -> gate -> render fallback)"
```

---

## Task 3: `DocumentProcessor::fetchUrl` (public raw-body fetch)

**Files:**
- Modify: `app/Services/Knowledge/DocumentProcessor.php`
- Test: `tests/Unit/Services/DocumentProcessorFetchTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/DocumentProcessorFetchTest.php`:
```php
    public function test_fetch_url_returns_raw_body_for_public_url(): void
    {
        Http::fake(['1.1.1.1/raw' => Http::response('<html><body><p>Raw body here</p></body></html>', 200)]);

        $body = app(DocumentProcessor::class)->fetchUrl('http://1.1.1.1/raw');

        $this->assertStringContainsString('Raw body here', $body);
        $this->assertStringContainsString('<html>', $body);
    }

    public function test_fetch_url_rejects_loopback(): void
    {
        $this->expectException(\Throwable::class);
        app(DocumentProcessor::class)->fetchUrl('http://127.0.0.1/admin');
    }
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=DocumentProcessorFetchTest`
Expected: FAIL — method `fetchUrl` not found.

- [ ] **Step 3: Extract `fetchUrl` and reuse it in `extractFromUrl`**

In `app/Services/Knowledge/DocumentProcessor.php`, replace the private `extractFromUrl` method with a public `fetchUrl` + a thin `extractFromUrl`:
```php
    /** Fetch the raw body of a public URL (SSRF-guarded, redirects disabled). */
    public function fetchUrl(string $url): string
    {
        if (! SafeExternalUrl::isSafe($url)) {
            Log::warning('[DocumentProcessor] Rejected non-public URL at fetch time', ['url' => $url]);
            throw new \Exception("Refusing to fetch non-public URL: {$url}");
        }

        $response = Http::timeout(30)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception("Failed to fetch URL: {$url}");
        }

        return $response->body();
    }

    private function extractFromUrl(string $url): string
    {
        Log::debug('[DocumentProcessor] (IS $) Extracting from URL', ['url' => $url]);

        return $this->extractHtml($this->fetchUrl($url));
    }
```

- [ ] **Step 4: Run it — expect GREEN**

Run: `php artisan test --filter=DocumentProcessorFetchTest`
Expected: PASS (new tests + the existing 3 fetch tests, which still route through `extract()`).

- [ ] **Step 5: Full suite + commit**

Run: `php artisan test`
Expected: PASS.
```bash
git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/DocumentProcessorFetchTest.php
git commit -m "refactor(knowledge): expose DocumentProcessor::fetchUrl for render-on-fallback"
```

---

## Task 4: Wire `RenderOnFallback` into `SiteCrawler` + heal-on-recrawl

**Files:**
- Modify: `app/Services/Crawler/SiteCrawler.php`
- Test: `tests/Unit/Services/Crawler/SiteCrawlerTest.php` (append)

- [ ] **Step 1: Write the failing heal test** (fake renderer "renders" a shell into real content)

Append to `tests/Unit/Services/Crawler/SiteCrawlerTest.php` (add `use App\Services\Crawler\PageRenderer;` and `use Mockery;` to imports):
```php
    public function test_skipped_item_heals_on_recrawl_when_rendering_enabled(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        // Pre-existing SkippedNoContent item with an UNCHANGED shell hash.
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        $item = KnowledgeItem::factory()->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::SkippedNoContent,
                'metadata' => ['crawl_session_id' => 1, 'content_hash' => 'sha256:'.hash('sha256', $shell), 'skipped_reason' => 'no_content'],
            ]);

        // Fake renderer: enabled, and "renders" the shell into a real page.
        $real = '<html><body><main><p>Our Bhutan cultural tours include guided treks, monastery visits, and homestays across the kingdom every season.</p></main></body></html>';
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldReceive('render')->andReturn($real);
        $this->app->instance(PageRenderer::class, $renderer);

        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Refresh,
            'status' => CrawlSessionStatus::Running,
        ]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $crawler = app(SiteCrawler::class);
        $crawler->setSleeper(fn () => null);
        $crawler->crawl($tenant, $session);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Pending, $item->status); // re-attempted, rendered, queued for embedding
        $this->assertStringContainsString('cultural tours', (string) $item->content);
        $this->assertSame(1, $session->refresh()->pages_indexed);
        Bus::assertDispatched(ProcessKnowledgeItem::class);
    }

    public function test_render_attempted_skipped_item_is_not_re_rendered_on_unchanged_recrawl(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        KnowledgeItem::factory()->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::SkippedNoContent,
                'metadata' => [
                    'content_hash' => 'sha256:'.hash('sha256', $shell),
                    'skipped_reason' => 'no_content',
                    'render_attempted_at' => now()->subDay()->toIso8601String(),
                ],
            ]);

        // Rendering on, but the page was already render-attempted + hash unchanged → must NOT re-render.
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldNotReceive('render');
        $this->app->instance(PageRenderer::class, $renderer);

        $session = CrawlSession::factory()->forTenant($tenant)->create(['mode' => CrawlMode::Refresh, 'status' => CrawlSessionStatus::Running]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $crawler = app(SiteCrawler::class);
        $crawler->setSleeper(fn () => null);
        $crawler->crawl($tenant, $session);

        $this->assertSame(1, $session->refresh()->pages_skipped_unchanged);
    }
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=test_skipped_item_heals_on_recrawl_when_rendering_enabled`
Expected: FAIL — the unchanged-hash item is hash-skipped (Phase-1 behavior); never re-attempted.

- [ ] **Step 3: Swap deps to `RenderOnFallback` and add the heal-bypass**

In `app/Services/Crawler/SiteCrawler.php`:

(a) Replace the two constructor dependencies `DocumentProcessor $processor` and `ContentSufficiency $sufficiency` with `RenderOnFallback $resolver`. Update the `use` imports: remove `App\Services\Knowledge\DocumentProcessor` and `App\Services\Knowledge\ContentSufficiency` if now unused (keep them only if `extractTitle`/others reference them — they do not), add `use App\Services\Crawler\RenderOnFallback;`.

(b) Change the content-hash dedup block (currently lines ~135-146) to NOT skip a `SkippedNoContent` item when rendering is enabled:
```php
                $contentHash = 'sha256:'.hash('sha256', $body);
                // Heal candidate: a skipped page gets ONE render attempt when
                // rendering is enabled. Once render-attempted (render_attempted_at
                // set) it is no longer a heal candidate, so an unchanged-hash page
                // is hash-skipped instead of re-rendered (~15s) every crawl. A
                // content-hash CHANGE still re-processes it (hash mismatch below).
                $healCandidate = $this->resolver->renderingEnabled()
                    && $existing !== null
                    && $existing->status === KnowledgeItemStatus::SkippedNoContent
                    && empty($existing->metadata['render_attempted_at'] ?? null);
                if ($existing && ! $healCandidate && ($existing->metadata['content_hash'] ?? null) === $contentHash) {
                    $metadata = array_merge((array) $existing->metadata, [
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                        'crawl_session_id' => $session->id,
                    ]);
                    $existing->update(['metadata' => $metadata]);
                    $pagesSkippedUnchanged++;

                    continue;
                }
```

(c) Replace the `extractHtml` + `isSufficient` lines (currently ~148 and ~168) with `resolve()`. The block from `$cleanText = $this->processor->extractHtml($body);` through the sufficient/insufficient branches becomes:
```php
                $resolution = $this->resolver->resolve($url, $body);
                $cleanText = $resolution['text'];
                $title = $this->extractTitle($body) ?: $url;

                $attributes = [
                    'tenant_id' => $tenant->id,
                    'type' => 'webpage',
                    'url_normalized' => $normalized,
                ];
                $values = [
                    'title' => $title,
                    'source_url' => $url,
                    'content' => $cleanText,
                    'metadata' => [
                        'crawl_session_id' => $session->id,
                        'content_hash' => $contentHash,
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                    ],
                ];

                if (! $resolution['sufficient']) {
                    $values['status'] = KnowledgeItemStatus::SkippedNoContent;
                    $values['metadata']['skipped_reason'] = 'no_content';
                    $values['metadata']['skipped_at'] = now()->toIso8601String();
                    // Stamp the render attempt so an unrenderable page isn't
                    // re-rendered every crawl (P2-8). Only when rendering ran.
                    if ($this->resolver->renderingEnabled()) {
                        $values['metadata']['render_attempted_at'] = now()->toIso8601String();
                    }
                    $skipped = KnowledgeItem::updateOrCreate($attributes, $values);
                    $skipped->chunks()->delete();
                    $pagesSkippedNoContent++;
                    ($this->sleeper)($crawlDelay);

                    continue;
                }

                $values['status'] = KnowledgeItemStatus::Pending;
                $item = KnowledgeItem::updateOrCreate($attributes, $values);
```
(The `ProcessKnowledgeItem::dispatch` try/catch + `$pagesIndexed++` + final `($this->sleeper)($crawlDelay)` that follow are unchanged.)

- [ ] **Step 4: Run it — expect GREEN, plus the Phase-1 crawler tests stay green**

Run: `php artisan test --filter=SiteCrawlerTest`
Expected: PASS — the heal test passes; `test_happy_path_indexes_pages`, `test_diff_skip_for_unchanged_content_hash`, `test_javascript_rendered_shell_is_marked_skipped_no_content`, `test_recrawl_to_shell_deletes_stale_chunks` all still pass (rendering is off by default in tests → resolve() == Phase-1 extract+gate; heal-bypass inactive).

- [ ] **Step 5: Full suite + commit**

Run: `php artisan test`
Expected: PASS.
```bash
git add app/Services/Crawler/SiteCrawler.php tests/Unit/Services/Crawler/SiteCrawlerTest.php
git commit -m "feat(crawler): render-on-fallback in SiteCrawler; heal SkippedNoContent on recrawl"
```

---

## Task 5: Render-on-fallback in the manual-add job

**Files:**
- Modify: `app/Jobs/ProcessKnowledgeItem.php`
- Modify: `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`, `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` (pass the new 4th arg)
- Test: add a manual-add render test to `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`

- [ ] **Step 1: Write the failing manual-add render test**

Append to `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` (add `use App\Services\Crawler\PageRenderer;`, `use App\Services\Crawler\RenderOnFallback;`):
```php
    public function test_manual_webpage_add_renders_on_fallback(): void
    {
        Queue::fake([GenerateEmbeddings::class]);

        $tenant = Tenant::create(['name' => 'Render Co', 'slug' => 'render-co-'.uniqid(), 'status' => 'active']);
        $item = KnowledgeItem::create([
            'tenant_id' => $tenant->id, 'type' => 'webpage', 'title' => 'SPA',
            'source_url' => 'https://1.1.1.1/spa', 'status' => 'pending',
        ]);

        // HTTP body is a shell; the renderer turns it into real content.
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        Http::fake(['1.1.1.1/spa' => Http::response($shell, 200)]);
        $real = '<html><body><main><p>Our Bhutan cultural tours run year round with licensed guides, treks, and monastery homestays for every traveller.</p></main></body></html>';
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldReceive('render')->andReturn($real);
        $this->app->instance(PageRenderer::class, $renderer);

        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(KnowledgeItemWorkflow::class),
            app(ContentSufficiency::class),
            app(RenderOnFallback::class),
        );

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Processing, $item->status);
        $this->assertStringContainsString('cultural tours', (string) $item->content);
        Queue::assertPushed(GenerateEmbeddings::class);
    }
```

- [ ] **Step 2: Update the existing `handle()` call sites to pass the 4th arg**

In `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`, the three existing `->handle(...)` calls (`test_process_job_leaves_item_in_processing_until_embeddings_complete`, `test_process_job_marks_skipped_when_content_insufficient`, `test_process_job_catch_does_not_prematurely_mark_failed`) gain a 4th argument `app(RenderOnFallback::class)`. In `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` (add `use App\Services\Crawler\RenderOnFallback;`), the four `->handle($x, app(KnowledgeItemWorkflow::class), app(ContentSufficiency::class))` calls gain `, app(RenderOnFallback::class)` as well.

- [ ] **Step 3: Run it — expect RED**

Run: `php artisan test --filter=KnowledgeStatusFlowTest`
Expected: FAIL — `handle()` has 3 params; the new 4th arg / render path doesn't exist.

- [ ] **Step 4: Add render-on-fallback to the job's webpage path**

In `app/Jobs/ProcessKnowledgeItem.php`, add `use App\Services\Crawler\RenderOnFallback;`. Change `handle()` to take the resolver and route the manual-webpage path through it:
```php
    public function handle(DocumentProcessor $processor, KnowledgeItemWorkflow $workflow, ContentSufficiency $gate, RenderOnFallback $resolver): void
    {
        Log::debug('[Knowledge] (NO $) Processing item', [
            'item_id' => $this->item->id,
            'type' => $this->item->type,
        ]);

        $workflow->markProcessing($this->item);

        try {
            [$text, $sufficient] = $this->resolveContent($processor, $gate, $resolver);

            if (! $sufficient) {
                $this->markSkipped($workflow);

                return;
            }

            if (in_array($this->item->type, ['webpage', 'document'], true)) {
                $this->item->update(['content' => $text]);
            }

            $chunks = $processor->chunk($text);

            if ($chunks === []) {
                $this->markSkipped($workflow);

                return;
            }

            DB::transaction(function () use ($chunks): void {
                $this->item->chunks()->delete();

                $now = now();
                $rows = [];
                foreach ($chunks as $index => $chunkContent) {
                    $rows[] = [
                        'knowledge_item_id' => $this->item->id,
                        'content' => $chunkContent,
                        'chunk_index' => $index,
                        'embedding' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                KnowledgeChunk::insert($rows);
            });

            GenerateEmbeddings::dispatch($this->item);

            Log::debug('[Knowledge] (NO $) Chunks written; embedding job dispatched', [
                'item_id' => $this->item->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] Processing failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Resolve clean text + sufficiency. A manual webpage add (source_url, no
     * stored content) fetches the body and runs render-on-fallback; every other
     * case uses the already-clean content via DocumentProcessor::extract.
     *
     * @return array{0: string, 1: bool}
     */
    private function resolveContent(DocumentProcessor $processor, ContentSufficiency $gate, RenderOnFallback $resolver): array
    {
        $item = $this->item;

        if ($item->type === 'webpage'
            && ($item->content === null || $item->content === '')
            && $item->source_url !== null) {
            $body = $processor->fetchUrl($item->source_url);
            $resolution = $resolver->resolve($item->source_url, $body);

            return [$resolution['text'], $resolution['sufficient']];
        }

        $text = $processor->extract($item);

        return [$text, $gate->isSufficient($text)];
    }
```

- [ ] **Step 5: Run the job tests — expect GREEN**

Run: `php artisan test --filter=KnowledgeStatusFlowTest && php artisan test --filter=ProcessKnowledgeItemIdempotencyTest`
Expected: PASS (incl. the new render test; idempotency unaffected since type=`text` uses the `extract` path).

- [ ] **Step 6: Full suite + commit**

Run: `php artisan test`
Expected: PASS.
```bash
git add app/Jobs/ProcessKnowledgeItem.php tests/Unit/Jobs/KnowledgeStatusFlowTest.php tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php
git commit -m "feat(knowledge): render-on-fallback for manual webpage adds"
```

---

## Task 6: Verification & wrap-up

- [ ] **Step 1: Full suite + static analysis + style**

Run: `php artisan test` (expect green), `./vendor/bin/phpstan analyse` (expect 0 errors — add a `@return` docblock if PHPStan flags the `resolve()` array shape), `./vendor/bin/pint --test` (fix + commit `style(pint): apply auto-fixes` if flagged).

- [ ] **Step 2: Live render smoke (Layer 3)** — the real end-to-end proof

```bash
# Set the Browsershot env (paths confirmed in Task 0), then:
php artisan tinker
```
Create a throwaway tenant pointed at `https://bookbhutantour.com` on the `professional` plan, set `config(['services.crawler.js_rendering' => true])` (+ node/chrome paths), run `app(SiteCrawler::class)->setSleeper(fn () => null)->crawl($tenant, $session)`, and confirm: pages now end **`Ready`** (or `Pending`→`Ready` after embeddings) with **real chunks** (tour content, not boilerplate), `pages_indexed > 0`, session `completed`/`partial`. Then re-run the Phase-1 live check with rendering **off** and confirm pages still `SkippedNoContent`. **Delete the throwaway tenant + items + session afterward.**

- [ ] **Step 3: Browser view (optional)** — log in as that tenant's owner, open `/knowledge`, confirm items show `ready` with chunk counts and the Show page renders the extracted tour text. Clean up the staged login.

- [ ] **Step 4: `/simplify` → Pint → `/simplify` → Pint, then open the PR** (base `feat/scraping-clean-extraction`; note it merges after #41). The PR body MUST include: the headless-render SSRF disclosure + the hard deploy gate ("do not enable `CRAWLER_JS_RENDERING` in prod until the egress-filtering follow-up ships"), and a checkbox/issue for that follow-up PR.

---

## Self-review notes
- Phase-1 behavior is preserved when `js_rendering` is off: `resolve()` reduces to `extractHtml` + `isSufficient` (renderer returns null), and `healCandidate` is false, so the dedup and SPA-skip tests are unchanged.
- `handle()` signature grows to 4 args; both job test files are updated in Task 5. The container injects the 4th arg automatically for real dispatches.
- `SiteCrawler` drops its `DocumentProcessor`/`ContentSufficiency` deps in favor of `RenderOnFallback` (which holds them) — a net simplification, no behavior change when rendering is off.

## Out of scope (per spec)
Per-tenant rendering toggle; document/PDF rendering; screenshots; render-concurrency throttling; auth-walled pages.

**Render-path egress filtering (SSRF mitigation) is a named, mandatory-before-prod FOLLOW-UP PR — not built here (P2-7).** Phase 2 ships the renderer behind `CRAWLER_JS_RENDERING` (off by default) with the initial-URL `SafeExternalUrl` guard, a `PageRenderer` security docblock, a hard deploy gate, and PR-body disclosure. The follow-up PR must add Puppeteer request-interception (or a filtering proxy) blocking private/link-local egress incl. redirect hops, and should fold in the pre-existing crawl redirect-SSRF fix. The PR description for Phase 2 must state: **do not enable `CRAWLER_JS_RENDERING` in production until that follow-up ships.**
