<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentProcessor
{
    private const CHUNK_SIZE = 500;

    private const CHUNK_OVERLAP = 50;

    private const MIN_CHUNK_CHARS = 50;

    /** Clean text for a KnowledgeItem by type. Webpage content is already cleaned. */
    public function extract(KnowledgeItem $item): string
    {
        return match ($item->type) {
            'document' => $this->extractFromFile($item->file_path ?? ''),
            'webpage' => $item->content !== null && $item->content !== ''
                ? $item->content
                : $this->extractFromUrl($item->source_url ?? ''),
            'faq', 'text' => $item->content ?? '',
            default => '',
        };
    }

    private function extractFromFile(string $filePath): string
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

    private function extractFromUrl(string $url): string
    {
        Log::debug('[DocumentProcessor] (IS $) Extracting from URL', [
            'url' => $url,
        ]);

        if (! SafeExternalUrl::isSafe($url)) {
            Log::warning('[DocumentProcessor] Rejected non-public URL at fetch time', [
                'url' => $url,
            ]);
            throw new \Exception("Refusing to fetch non-public URL: {$url}");
        }

        try {
            $response = Http::timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            if (! $response->successful()) {
                throw new \Exception("Failed to fetch URL: {$url}");
            }

            return $this->extractHtml($response->body());
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

        return $this->cleanText($pdf->getText());
    }

    private function extractFromText(string $path): string
    {
        return $this->cleanText(file_get_contents($path));
    }

    private function extractFromDocx(string $path): string
    {
        $content = '';

        $zip = new \ZipArchive;

        if ($zip->open($path) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlContent) {
                // OOXML splits text across <w:t> runs within <w:r> elements;
                // strip_tags alone would merge adjacent runs into one word.
                // Insert a space at every </w:t> and a newline at every </w:p>
                // before stripping.
                $xmlContent = str_replace(['</w:t>', '</w:p>'], [' ', "\n"], $xmlContent);
                $content = strip_tags($xmlContent);
            }
        }

        return $this->cleanText($content);
    }

    public function extractHtml(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $tagsToRemove = ['script', 'style', 'nav', 'header', 'footer', 'svg', 'iframe', 'form', 'noscript', 'aside', 'button', 'input', 'select', 'textarea', 'dialog', 'menu'];
        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }

        $xpath = new \DOMXPath($dom);
        $comments = $xpath->query('//comment()');
        if ($comments) {
            foreach ($comments as $comment) {
                $comment->parentNode?->removeChild($comment);
            }
        }

        // Block elements carry no whitespace between them in textContent, so
        // "<h1>Our Bakery</h1><p>We bake" collapses to "Our BakeryWe bake".
        // Insert a newline text node before each block element so it is
        // separated from preceding siblings AND preceding text in the same
        // parent (e.g. "<div>Intro<p>Body</p></div>" → "Intro\nBody").
        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br', 'section', 'article', 'blockquote', 'pre', 'tr', 'td', 'th', 'ul', 'ol', 'table', 'main'];
        foreach ($blockTags as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $element) {
                $element->parentNode?->insertBefore($dom->createTextNode("\n"), $element);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanText($text);
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn (string $line) => $line !== '');

        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Split text into overlapping chunks for context preservation.
     * Chunk size + overlap are spec-locked at 500 / 50; no caller varies them.
     *
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text);

        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (strlen($currentChunk) + strlen($paragraph) + 1 <= self::CHUNK_SIZE) {
                $currentChunk .= ($currentChunk === '' ? '' : "\n\n").$paragraph;

                continue;
            }

            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $this->getOverlapText($currentChunk);
            }

            if (strlen($paragraph) > self::CHUNK_SIZE) {
                $sentenceChunks = $this->splitLargeParagraph($paragraph);
                foreach ($sentenceChunks as $sentenceChunk) {
                    $chunks[] = $sentenceChunk;
                }
                $currentChunk = $this->getOverlapText(end($sentenceChunks) ?: '');
            } else {
                $currentChunk .= ($currentChunk === '' ? '' : "\n\n").$paragraph;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = $currentChunk;
        }

        return array_values(array_filter(
            $chunks,
            fn (string $chunk) => strlen(trim($chunk)) >= self::MIN_CHUNK_CHARS,
        ));
    }

    /** @return array<int, string> */
    private function splitLargeParagraph(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);

        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) + 1 <= self::CHUNK_SIZE) {
                $currentChunk .= ($currentChunk === '' ? '' : ' ').$sentence;

                continue;
            }

            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
            }
            $currentChunk = $sentence;
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    private function getOverlapText(string $text): string
    {
        if (strlen($text) <= self::CHUNK_OVERLAP) {
            return $text;
        }

        $overlapSection = substr($text, -self::CHUNK_OVERLAP * 2);

        if (preg_match('/[.!?]\s+([^.!?]+)$/', $overlapSection, $matches)) {
            return $matches[1];
        }

        $lastPart = substr($text, -self::CHUNK_OVERLAP);

        if (preg_match('/^\S*\s+(.+)/', $lastPart, $matches)) {
            return $matches[1];
        }

        return $lastPart;
    }
}
