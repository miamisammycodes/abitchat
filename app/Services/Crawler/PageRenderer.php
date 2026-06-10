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
 * endpoints. SafeExternalUrl::isSafe guards the initial URL; ALL Chromium
 * egress (redirect hops + page-JS fetches) is routed through the validate-and-
 * pin egress proxy (resources/node/egress-proxy.mjs) so every connection is
 * re-checked against the private denylist (rebinding-safe). enabled() is
 * fail-closed: rendering stays OFF unless CRAWLER_EGRESS_PROXY is set, so the
 * proxy can never be bypassed. Keep CRAWLER_JS_RENDERING off until the proxy is
 * deployed alongside the app (see spec).
 */
class PageRenderer
{
    public function enabled(): bool
    {
        return (bool) config('services.crawler.js_rendering', false)
            && filled(config('services.crawler.egress_proxy'));
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

            // Route ALL Chromium egress through the validate-and-pin proxy so
            // redirect hops + page-JS fetches are re-checked against the private
            // denylist (rebinding-safe). proxy-bypass-list "<-loopback>" forces
            // even localhost targets through the proxy (default Chromium bypasses
            // loopback), so an attacker page can't reach 127.0.0.1 directly. The
            // enabled() interlock guarantees egress_proxy is non-empty here.
            $shot->setProxyServer((string) config('services.crawler.egress_proxy'))
                ->addChromiumArguments(['proxy-bypass-list' => '<-loopback>']);

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
