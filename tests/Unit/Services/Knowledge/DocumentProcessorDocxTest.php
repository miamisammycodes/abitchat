<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

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
        $tmp = tempnam(sys_get_temp_dir(), 'docxtest_') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $bodyXml . '</w:body>'
            . '</w:document>';
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        $relative = 'docxtest/' . basename($tmp);
        Storage::disk('local')->put($relative, file_get_contents($tmp));
        unlink($tmp);

        return $relative;
    }

    public function test_adjacent_text_runs_get_space_separated(): void
    {
        $bodyXml = '<w:p><w:r><w:t>price</w:t></w:r><w:r><w:t>list</w:t></w:r></w:p>';
        $path = $this->makeDocx($bodyXml);

        $text = app(DocumentProcessor::class)->extractFromFile($path);

        $this->assertStringContainsString('price list', $text);
        $this->assertStringNotContainsString('pricelist', $text);
    }

    public function test_paragraphs_are_newline_separated(): void
    {
        $bodyXml = '<w:p><w:r><w:t>First paragraph.</w:t></w:r></w:p>'
                 . '<w:p><w:r><w:t>Second paragraph.</w:t></w:r></w:p>';
        $path = $this->makeDocx($bodyXml);

        $text = app(DocumentProcessor::class)->extractFromFile($path);

        $this->assertStringContainsString('First paragraph.', $text);
        $this->assertStringContainsString('Second paragraph.', $text);
        $this->assertStringNotContainsString('First paragraph.Second paragraph.', $text);
    }
}
