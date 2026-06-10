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
     * `rendered` is true only when the headless render actually executed and
     * returned HTML (callers use it to stamp render_attempted_at — a render
     * that returned null must NOT be recorded as attempted, so it can retry).
     *
     * `$allowRender` is the per-crawl budget gate: when false (budget exhausted)
     * an insufficient page short-circuits WITHOUT rendering, staying heal-eligible
     * for a future crawl (no render_attempted_at stamp, since no render ran).
     *
     * @return array{text: string, sufficient: bool, rendered: bool}
     */
    public function resolve(string $url, string $httpBody, bool $allowRender = true): array
    {
        $text = $this->processor->extractHtml($httpBody);

        if ($this->gate->isSufficient($text, $httpBody)) {
            return ['text' => $text, 'sufficient' => true, 'rendered' => false];
        }

        if (! $allowRender) {
            return ['text' => $text, 'sufficient' => false, 'rendered' => false];
        }

        $rendered = $this->renderer->render($url);

        if ($rendered !== null) {
            $renderedText = $this->processor->extractHtml($rendered);

            if ($this->gate->isSufficient($renderedText, $rendered)) {
                return ['text' => $renderedText, 'sufficient' => true, 'rendered' => true];
            }
        }

        return ['text' => $text, 'sufficient' => false, 'rendered' => $rendered !== null];
    }
}
