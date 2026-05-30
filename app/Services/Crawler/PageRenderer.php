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

            // base44/React SPAs (e.g. bookbhutantour.com) never reach network
            // idle, so waitUntilNetworkIdle() always times out — Task 0 verified
            // a fixed post-load delay instead. node_modules is set
            // unconditionally because pnpm's non-hoisted layout breaks
            // puppeteer's own module resolution from Browsershot's bin script.
            $shot = Browsershot::url($url)
                ->setNodeModulePath(base_path('node_modules'))
                ->setDelay((int) config('services.crawler.render_delay', 3000))
                ->timeout((int) config('services.crawler.render_timeout', 45));

            if ($node = config('services.crawler.node_binary')) {
                $shot->setNodeBinary((string) $node);
            }
            if ($npm = config('services.crawler.npm_binary')) {
                $shot->setNpmBinary((string) $npm);
            }
            // chrome_path is effectively MANDATORY on macOS + pnpm: puppeteer's
            // auto-resolve fails to find the downloaded "Chrome for Testing.app"
            // binary, so without this set render() silently returns null. Point
            // BROWSERSHOT_CHROME_PATH at the puppeteer-downloaded Chrome (see
            // ~/.cache/puppeteer/chrome/.../Google Chrome for Testing).
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
