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
