<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

/**
 * Decides whether extracted text is real content or an empty/SPA-shell page.
 *
 * Combined signal, so a thin-but-real page is NOT buried:
 * - hard floor: fewer than HARD_FLOOR_WORDS words is always insufficient;
 * - SPA shell: short text (< SPA_CEILING_WORDS) AND the raw HTML shows an
 *   SPA marker — a known framework mount element (React/Next/Nuxt/Gatsby) or
 *   a page dominated by inline JavaScript. Both are absent from server-rendered
 *   pages, so a short real page (e.g. a WordPress contact page) stays indexed.
 */
class ContentSufficiency
{
    public const HARD_FLOOR_WORDS = 3;

    public const SPA_CEILING_WORDS = 25;

    /** Inline-script bytes / total bytes above this means the page is a JS shell. */
    public const SPA_SCRIPT_RATIO = 0.25;

    /** Mount elements used by SPA frameworks; server-rendered pages don't use these ids. */
    private const SPA_MOUNT_PATTERN = '/<[a-z][a-z0-9]*[^>]*\bid=["\'](?:root|__next|__nuxt|___gatsby)["\']/i';

    public function isSufficient(string $cleanText, ?string $rawHtml = null): bool
    {
        $words = $this->wordCount($cleanText);

        if ($words < self::HARD_FLOOR_WORDS) {
            return false;
        }

        if ($words < self::SPA_CEILING_WORDS
            && $rawHtml !== null
            && $this->looksLikeSpaShell($rawHtml)) {
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

    private function looksLikeSpaShell(string $rawHtml): bool
    {
        if (preg_match(self::SPA_MOUNT_PATTERN, $rawHtml) === 1) {
            return true;
        }

        $length = strlen($rawHtml);
        if ($length === 0) {
            return false;
        }

        $scriptBytes = 0;
        if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $rawHtml, $matches) > 0) {
            foreach ($matches[1] as $script) {
                $scriptBytes += strlen($script);
            }
        }

        return ($scriptBytes / $length) >= self::SPA_SCRIPT_RATIO;
    }
}
