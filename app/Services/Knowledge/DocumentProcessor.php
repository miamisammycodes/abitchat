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
        // Remove script and style elements
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Remove nav, header, footer elements
        $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $html);
        $html = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', '', $html);
        $html = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $html);

        // Convert block elements to newlines
        $html = preg_replace('/<(p|div|br|h[1-6]|li)[^>]*>/i', "\n", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanText($text);
    }

    private function cleanText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\r\n|\r/', "\n", $text);

        // Replace multiple spaces with single space
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Replace multiple newlines with double newline
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim whitespace from each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        return trim($text);
    }
}
