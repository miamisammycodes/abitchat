<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\TextChunker;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_returns_empty_array_for_empty_string(): void
    {
        $chunker = new TextChunker();

        $this->assertSame([], $chunker->chunk(''));
    }

    public function test_returns_empty_array_for_whitespace_only(): void
    {
        $chunker = new TextChunker();

        $this->assertSame([], $chunker->chunk("   \n\n  \t  "));
    }

    public function test_filters_out_chunks_shorter_than_50_chars(): void
    {
        $chunker = new TextChunker();

        // 30 chars — below the 50-char floor.
        $this->assertSame([], $chunker->chunk('Too short to be a real chunk.'));
    }

    public function test_returns_single_chunk_when_text_fits(): void
    {
        $chunker = new TextChunker(chunkSize: 500);
        $text = str_repeat('Lorem ipsum dolor sit amet. ', 5); // ~140 chars

        $chunks = $chunker->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Lorem ipsum dolor sit amet', $chunks[0]);
    }

    public function test_splits_into_multiple_chunks_when_text_exceeds_chunk_size(): void
    {
        $chunker = new TextChunker(chunkSize: 200);

        // Five paragraphs of ~120 chars each separated by blank lines.
        $paragraph = str_repeat('All work and no play makes Jack a dull boy. ', 3);
        $text = implode("\n\n", array_fill(0, 5, $paragraph));

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertNotEmpty(trim($chunk));
        }
    }

    public function test_splits_a_single_oversize_paragraph_by_sentence(): void
    {
        $chunker = new TextChunker(chunkSize: 100);

        // One paragraph well over chunkSize, with several sentence boundaries.
        $text = 'First sentence runs about thirty characters. ' .
                'Second sentence is another thirty chars. ' .
                'Third sentence completes the trio. ' .
                'Fourth sentence pushes us past one hundred.';

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));
        // No single chunk should be wildly larger than chunkSize after sentence split.
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(150, strlen($chunk));
        }
    }

    public function test_preserves_input_content_across_chunks(): void
    {
        $chunker = new TextChunker(chunkSize: 200);

        $marker = 'UNIQUE_MARKER_XYZ_42';
        $text = "Some intro paragraph that is reasonably long.\n\n" .
                str_repeat("Filler paragraph content here. ", 3) . "\n\n" .
                "Tail paragraph contains {$marker} which must survive chunking.";

        $chunks = $chunker->chunk($text);

        $combined = implode(' ', $chunks);
        $this->assertStringContainsString($marker, $combined);
    }

    public function test_respects_custom_chunk_size(): void
    {
        $smallChunker = new TextChunker(chunkSize: 100);
        $largeChunker = new TextChunker(chunkSize: 1000);

        $paragraph = str_repeat('Paragraph content with some real length. ', 3);
        $text = implode("\n\n", array_fill(0, 6, $paragraph));

        $smallChunks = $smallChunker->chunk($text);
        $largeChunks = $largeChunker->chunk($text);

        $this->assertGreaterThan(count($largeChunks), count($smallChunks));
    }
}
