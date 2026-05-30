<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentProcessorProcessTest extends TestCase
{
    private function makeItem(array $overrides = []): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'DP Co',
            'slug' => 'dp-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        return KnowledgeItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'DP Item',
            'content' => str_repeat('Sentence one is reasonably long and detailed. ', 5),
            'status' => 'pending',
        ], $overrides));
    }

    public function test_process_text_item_returns_chunks(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem();

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertIsString($chunk);
            $this->assertGreaterThanOrEqual(50, strlen(trim($chunk)));
        }
    }

    public function test_process_faq_item_returns_chunks(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem(['type' => 'faq']);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertNotEmpty($chunks);
    }

    public function test_process_unknown_type_returns_empty(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem();
        $item->forceFill(['type' => 'unknown_type']);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertSame([], $chunks);
    }

    public function test_process_text_item_with_empty_content_returns_empty(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem(['content' => '']);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertSame([], $chunks);
    }

    public function test_process_chunks_filter_out_below_50_char_chunks(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem(['content' => 'Too short to survive chunking.']);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertSame([], $chunks);
    }

    public function test_process_splits_long_content_into_multiple_chunks(): void
    {
        $processor = new DocumentProcessor;
        $paragraph = str_repeat('All work and no play makes Jack a dull boy. ', 3);
        $body = implode("\n\n", array_fill(0, 6, $paragraph));
        $item = $this->makeItem(['content' => $body]);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_process_preserves_unique_marker_across_chunks(): void
    {
        $processor = new DocumentProcessor;
        $marker = 'UNIQUE_MARKER_XYZ_42';
        $body = "Intro paragraph that is reasonably long and detailed enough.\n\n".
                str_repeat('Filler content paragraph here for bulk. ', 3)."\n\n".
                "Tail paragraph contains {$marker} which must survive chunking.";
        $item = $this->makeItem(['content' => $body]);

        $chunks = $processor->chunk($processor->extract($item));

        $combined = implode(' ', $chunks);
        $this->assertStringContainsString($marker, $combined);
    }

    public function test_process_webpage_with_stored_content_does_not_fetch_url(): void
    {
        // Crawler stores CLEAN text in $item->content; extract() must reuse it
        // verbatim instead of re-fetching $item->source_url.
        Http::fake(['*' => Http::response('SHOULD_NOT_BE_CALLED', 200)]);
        Http::preventStrayRequests();

        $processor = new DocumentProcessor;
        $clean = 'Stored clean page text that is long enough to exceed the minimum chunk threshold for this test.';
        $item = $this->makeItem([
            'type' => 'webpage',
            'source_url' => 'https://example.com/about',
            'content' => $clean,
        ]);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertNotEmpty($chunks);
        Http::assertNothingSent();
    }

    public function test_extract_html_separates_adjacent_block_elements(): void
    {
        $processor = new DocumentProcessor;

        $text = $processor->extractHtml('<html><body><h1>Our Bakery</h1><p>We bake bread daily.</p><ul><li>Sourdough</li><li>Rye</li></ul></body></html>');

        $this->assertStringNotContainsString('BakeryWe', $text);
        $this->assertStringNotContainsString('SourdoughRye', $text);
        $this->assertStringContainsString('Our Bakery', $text);
        $this->assertStringContainsString('We bake', $text);
    }

    public function test_extract_html_separates_block_from_preceding_text_in_same_parent(): void
    {
        $processor = new DocumentProcessor;

        $this->assertStringNotContainsString('IntroBody', $processor->extractHtml('<div>Intro<p>Body</p></div>'));
        $this->assertStringNotContainsString('HeadingParagraph', $processor->extractHtml('<section>Heading<p>Paragraph</p></section>'));
        $this->assertStringNotContainsString('Labelvalue', $processor->extractHtml('<td>Label<div>value</div></td>'));
    }
}
