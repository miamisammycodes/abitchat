<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class BootstrapVendorResolutionTest extends TestCase
{
    public function test_bootstrap_does_not_hardcode_a_machine_specific_vendor_path(): void
    {
        $source = (string) file_get_contents(base_path('tests/bootstrap.php'));

        $this->assertStringNotContainsString(
            '/Users/sam/Dev/laravel/chatbot/vendor',
            $source,
            'tests/bootstrap.php must not hardcode a machine-specific absolute vendor path'
        );
        $this->assertStringContainsString(
            'git rev-parse --git-common-dir',
            $source,
            'tests/bootstrap.php must resolve the main-repo vendor dynamically via git-common-dir'
        );
    }

    public function test_resolve_main_vendor_returns_worktree_vendor_on_a_normal_checkout(): void
    {
        require_once base_path('tests/bootstrap.php');

        $worktreeRoot = dirname(base_path('tests'));

        // On a normal checkout (this repo IS the main repo), the resolver must
        // return the worktree's own vendor — git-common-dir resolves to a path
        // whose parent already equals the worktree root.
        $resolved = \tests_resolve_main_vendor($worktreeRoot);

        $this->assertSame($worktreeRoot.'/vendor', $resolved);
        $this->assertFileExists($resolved.'/autoload.php');
    }

    public function test_resolve_main_vendor_falls_back_when_resolved_path_is_missing(): void
    {
        require_once base_path('tests/bootstrap.php');

        // A bogus worktree root with no .git → git command fails / yields no
        // usable main vendor → fall back to "<root>/vendor".
        $bogusRoot = sys_get_temp_dir().'/nonexistent-worktree-'.uniqid();

        $resolved = \tests_resolve_main_vendor($bogusRoot);

        $this->assertSame($bogusRoot.'/vendor', $resolved);
    }
}
