<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentProcessorDocxTest extends TestCase
{
    /**
     * Build a minimal DOCX file (a ZIP containing word/document.xml) and
     * return the relative path on the local disk.
     */
    private function makeDocx(string $bodyXml): string
    {
        Storage::fake('local');
        $tmp = tempnam(sys_get_temp_dir(), 'docxtest_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE);
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$bodyXml.'</w:body>'
            .'</w:document>';
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        $relative = 'docxtest/'.basename($tmp);
        Storage::disk('local')->put($relative, file_get_contents($tmp));
        unlink($tmp);

        return $relative;
    }

    private function makeDocumentItem(string $filePath): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Docx Co',
            'slug' => 'docx-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'document',
            'title' => 'Docx Item',
            'file_path' => $filePath,
            'status' => 'pending',
        ]);
    }

    public function test_adjacent_text_runs_get_space_separated(): void
    {
        // Content is padded to survive the 50-char minimum chunk filter.
        // The run-separation assertion requires a long enough string to
        // actually appear in the output.
        $padding = ' and this extra text makes it long enough to survive chunking';
        $bodyXml = '<w:p><w:r><w:t>price</w:t></w:r><w:r><w:t>list'.$padding.'</w:t></w:r></w:p>';
        $path = $this->makeDocx($bodyXml);
        $item = $this->makeDocumentItem($path);

        $processor = app(DocumentProcessor::class);
        $chunks = $processor->chunk($processor->extract($item));

        $combined = implode(' ', $chunks);
        $this->assertStringContainsString('price list', $combined);
        $this->assertStringNotContainsString('pricelist', $combined);
    }

    public function test_paragraphs_are_newline_separated(): void
    {
        // Each paragraph must be ≥50 chars to survive the chunk filter.
        $p1 = 'First paragraph with enough content to clear the minimum length filter.';
        $p2 = 'Second paragraph with enough content to clear the minimum length filter.';
        $bodyXml = '<w:p><w:r><w:t>'.$p1.'</w:t></w:r></w:p>'
                 .'<w:p><w:r><w:t>'.$p2.'</w:t></w:r></w:p>';
        $path = $this->makeDocx($bodyXml);
        $item = $this->makeDocumentItem($path);

        $processor = app(DocumentProcessor::class);
        $chunks = $processor->chunk($processor->extract($item));

        $combined = implode("\n", $chunks);
        $this->assertStringContainsString($p1, $combined);
        $this->assertStringContainsString($p2, $combined);
        $this->assertStringNotContainsString($p1.$p2, $combined);
    }
}
