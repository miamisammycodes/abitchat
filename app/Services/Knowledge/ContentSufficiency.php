<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

/**
 * Decides whether extracted text is real content or an empty/SPA-shell page.
 *
 * Signals (combined, so a thin-but-real page is not buried):
 * - hard floor: fewer than HARD_FLOOR_WORDS words is always insufficient;
 * - SPA shell: short text AND its length is a tiny fraction of the raw HTML
 *   (the page is dominated by a JS bundle — base44/React/Vue/Next shells).
 */
class ContentSufficiency
{
    public const HARD_FLOOR_WORDS = 3;

    public const SPA_CEILING_WORDS = 25;

    public const SPA_TEXT_RATIO = 0.03;

    public function isSufficient(string $cleanText, ?string $rawHtml = null): bool
    {
        $words = $this->wordCount($cleanText);

        if ($words < self::HARD_FLOOR_WORDS) {
            return false;
        }

        if ($words < self::SPA_CEILING_WORDS
            && $rawHtml !== null
            && $this->looksLikeSpaShell($rawHtml, $cleanText)) {
            return false;
        }

        return true;
    }

    public function wordCount(string $text): int
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/\s+/', $trimmed));
    }

    private function looksLikeSpaShell(string $rawHtml, string $cleanText): bool
    {
        $htmlLength = strlen($rawHtml);

        return $htmlLength > 0
            && (strlen($cleanText) / $htmlLength) < self::SPA_TEXT_RATIO;
    }
}
