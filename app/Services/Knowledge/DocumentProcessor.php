<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentProcessor
{
    public function extractFromFile(string $filePath): string
    {
        $fullPath = Storage::disk('local')->path($filePath);

        if (! file_exists($fullPath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        Log::debug('[DocumentProcessor] (NO $) Extracting from file', [
            'path' => $filePath,
            'extension' => $extension,
        ]);

        return match ($extension) {
            'pdf' => $this->extractFromPdf($fullPath),
            'txt', 'md' => $this->extractFromText($fullPath),
            'doc', 'docx' => $this->extractFromDocx($fullPath),
            default => throw new \Exception("Unsupported file type: {$extension}"),
        };
    }

    public function extractFromUrl(string $url): string
    {
        Log::debug('[DocumentProcessor] (NO $) Extracting from URL', [
            'url' => $url,
        ]);

        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                throw new \Exception("Failed to fetch URL: {$url}");
            }

            $html = $response->body();

            return $this->extractTextFromHtml($html);
        } catch (\Exception $e) {
            Log::error('[DocumentProcessor] URL extraction failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function extractFromPdf(string $path): string
    {
        $parser = new PdfParser;
        $pdf = $parser->parseFile($path);

        $text = $pdf->getText();

        return $this->cleanText($text);
    }

    private function extractFromText(string $path): string
    {
        $content = file_get_contents($path);

        return $this->cleanText($content);
    }

    private function extractFromDocx(string $path): string
    {
        $content = '';

        $zip = new \ZipArchive;

        if ($zip->open($path) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlContent) {
                // Strip XML tags to get plain text
                $content = strip_tags($xmlContent);
            }
        }

        return $this->cleanText($content);
    }

    private function extractTextFromHtml(string $html): string
    {
        // Use DOMDocument for proper HTML parsing
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Remove non-content elements
        $tagsToRemove = ['script', 'style', 'nav', 'header', 'footer', 'svg', 'iframe', 'form', 'noscript', 'aside', 'button', 'input', 'select', 'textarea'];
        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            // Collect elements first (removing during iteration breaks the list)
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }

        // Remove HTML comments
        $xpath = new \DOMXPath($dom);
        $comments = $xpath->query('//comment()');
        if ($comments) {
            foreach ($comments as $comment) {
                $comment->parentNode?->removeChild($comment);
            }
        }

        // Extract text content — DOMDocument handles this cleanly
        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanText($text);
    }

    private function cleanText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\r\n|\r/', "\n", $text);

        // Replace multiple spaces/tabs with single space
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Trim whitespace from each line and remove empty lines
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn (string $line) => $line !== '');

        // Rejoin and collapse excessive newlines
        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
