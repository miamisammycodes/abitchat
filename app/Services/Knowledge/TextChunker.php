<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

class TextChunker
{
    private int $chunkSize;
    private int $overlap;

    public function __construct(int $chunkSize = 500, int $overlap = 50)
    {
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
    }

    /**
     * Split text into overlapping chunks for better context preservation.
     *
     * @return array<string>
     */
    public function chunk(string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        // First, try to split by paragraphs
        $paragraphs = $this->splitByParagraphs($text);

        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if (empty($paragraph)) {
                continue;
            }

            // If paragraph fits in current chunk
            if (strlen($currentChunk) + strlen($paragraph) + 1 <= $this->chunkSize) {
                $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $paragraph;
            } else {
                // Save current chunk if not empty
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;

                    // Start new chunk with overlap from previous
                    $overlapText = $this->getOverlapText($currentChunk);
                    $currentChunk = $overlapText;
                }

                // If paragraph itself is too large, split by sentences
                if (strlen($paragraph) > $this->chunkSize) {
                    $sentenceChunks = $this->splitLargeParagraph($paragraph);
                    foreach ($sentenceChunks as $sentenceChunk) {
                        $chunks[] = $sentenceChunk;
                    }
                    $currentChunk = $this->getOverlapText(end($sentenceChunks) ?: '');
                } else {
                    $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $paragraph;
                }
            }
        }

        // Don't forget the last chunk
        if (!empty(trim($currentChunk))) {
            $chunks[] = $currentChunk;
        }

        // Filter out very small chunks
        return array_values(array_filter($chunks, function ($chunk) {
            return strlen(trim($chunk)) >= 50;
        }));
    }

    private function splitByParagraphs(string $text): array
    {
        return preg_split('/\n\s*\n/', $text);
    }

    private function splitLargeParagraph(string $paragraph): array
    {
        // Split by sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);

        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) + 1 <= $this->chunkSize) {
                $currentChunk .= (empty($currentChunk) ? '' : ' ') . $sentence;
            } else {
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $sentence;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    private function getOverlapText(string $text): string
    {
        if (strlen($text) <= $this->overlap) {
            return $text;
        }

        // Try to get overlap at a sentence or word boundary
        $overlapSection = substr($text, -$this->overlap * 2);

        // Find the last sentence start
        if (preg_match('/[.!?]\s+([^.!?]+)$/', $overlapSection, $matches)) {
            return $matches[1];
        }

        // Otherwise, get last N characters at word boundary
        $lastPart = substr($text, -$this->overlap);

        // Find first word boundary
        if (preg_match('/^\S*\s+(.+)/', $lastPart, $matches)) {
            return $matches[1];
        }

        return $lastPart;
    }
}
