<?php

declare(strict_types=1);

namespace Tests\Feature\Inertia;

use App\Enums\Ability;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Static guard: every $page.props.auth.user.can.<key> reference in Vue templates
 * must resolve to a known snake_case Ability::cases() value.
 *
 * Catches typos like can.manage_billings, can.viewAnalyticsFull, can.view_analytics
 * that otherwise fail silently (undefined → false → control hides for EVERYONE).
 */
class VueCanKeyAlignmentTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function validAbilityKeys(): array
    {
        return collect(Ability::cases())
            ->map(fn (Ability $a): string => str_replace('-', '_', $a->value))
            ->all();
    }

    /**
     * @return list<array{file: string, line: int, key: string}>
     */
    private function scanForCanReferences(): array
    {
        $scanDirs = [
            resource_path('js/Pages/Client'),
            resource_path('js/Pages/Admin'),
            resource_path('js/Layouts'),
        ];

        $valid = $this->validAbilityKeys();
        $violations = [];

        foreach ($scanDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
                '/\.vue$/i',
            );

            foreach ($iterator as $file) {
                $path = $file->getPathname();
                $lines = file($path, FILE_IGNORE_NEW_LINES);

                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $lineIndex => $line) {
                    if (preg_match_all('/\$page\.props\.auth\.user\.can\.([a-z_]+)/', $line, $matches) === false) {
                        continue;
                    }

                    foreach ($matches[1] as $key) {
                        if (! in_array($key, $valid, true)) {
                            $violations[] = [
                                'file' => $path,
                                'line' => $lineIndex + 1,
                                'key' => $key,
                            ];
                        }
                    }
                }
            }
        }

        return $violations;
    }

    public function test_every_template_can_key_reference_resolves_to_a_known_ability_case(): void
    {
        $violations = $this->scanForCanReferences();

        $message = sprintf(
            "Found %d template can.X reference(s) that do not match any Ability case.\nValid keys: [%s]\n%s",
            count($violations),
            implode(', ', $this->validAbilityKeys()),
            collect($violations)
                ->map(fn (array $v): string => sprintf(
                    '  - can.%s in %s (line %d)',
                    $v['key'],
                    $v['file'],
                    $v['line'],
                ))
                ->implode("\n"),
        );

        $this->assertSame([], $violations, $message);
    }
}
