<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * D-09 guard test: assert that no Client FormRequest validates or references bot_custom_instructions.
 * Bot brain is platform-admin only and must remain outside the tenant role hierarchy entirely.
 */
class BotBrainInvariantTest extends TestCase
{
    public function test_no_client_form_request_references_bot_custom_instructions(): void
    {
        $requestsDir = __DIR__ . '/../../../app/Http/Requests/Client';

        if (! is_dir($requestsDir)) {
            $this->markTestSkipped('Client FormRequests directory not found.');
        }

        $files = $this->getAllPhpFiles($requestsDir);

        $this->assertNotEmpty(
            $files,
            'Expected at least one Client FormRequest file to exist for D-09 validation.'
        );

        foreach ($files as $filePath) {
            $contents = file_get_contents($filePath);
            $this->assertStringNotContainsString(
                'bot_custom_instructions',
                $contents,
                sprintf(
                    'D-09 violation: Client FormRequest file "%s" references bot_custom_instructions. ' .
                    'Bot brain is platform-admin only and must never be validated by tenant-side requests.',
                    basename($filePath)
                )
            );
        }
    }

    /**
     * @return list<string>
     */
    private function getAllPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }
}
