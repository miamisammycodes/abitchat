<?php

/**
 * Test bootstrap for git-worktree agents.
 *
 * Git worktrees share the parent repo's vendor/ directory.
 * Composer's classmap hard-codes paths pointing to the main repo's app/ dir.
 * This bootstrap strips all App\* and Database\* classmap entries so that
 * PSR-4 fallback resolves them from the worktree instead.
 *
 * On a normal (non-worktree) checkout this is a no-op: the resolver returns
 * the worktree's own vendor and the classmap rewrite targets the same paths
 * it would have anyway.
 */
if (! function_exists('tests_resolve_main_vendor')) {
    /**
     * Resolve the vendor/ directory that owns the shared autoloader.
     *
     * In a linked git worktree, `git rev-parse --git-common-dir` prints an
     * absolute path to the MAIN repo's .git; its parent is the main repo root,
     * whose vendor/ holds the real (shared) autoloader. On a normal checkout
     * the common-dir resolves to this repo's own .git, so the parent equals
     * the worktree root and we fall through to the worktree's own vendor.
     *
     * Falls back to "<worktreeRoot>/vendor" whenever the git command fails or
     * the resolved main vendor has no autoload.php (e.g. CI, fresh checkout).
     */
    function tests_resolve_main_vendor(string $worktreeRoot): string
    {
        $worktreeVendor = $worktreeRoot.'/vendor';

        $output = [];
        $exitCode = 1;
        @exec(
            'git -C '.escapeshellarg($worktreeRoot).' rev-parse --git-common-dir 2>/dev/null',
            $output,
            $exitCode,
        );

        if ($exitCode !== 0 || $output === []) {
            return $worktreeVendor;
        }

        $commonDir = trim((string) $output[0]);
        if ($commonDir === '') {
            return $worktreeVendor;
        }

        // Make the common-dir absolute relative to the worktree root.
        if (! str_starts_with($commonDir, '/')) {
            $commonDir = $worktreeRoot.'/'.$commonDir;
        }

        $mainRoot = dirname((string) realpath($commonDir) ?: $commonDir);
        $mainVendor = $mainRoot.'/vendor';

        if ($mainVendor !== $worktreeVendor && is_file($mainVendor.'/autoload.php')) {
            return $mainVendor;
        }

        return $worktreeVendor;
    }
}

$worktreeRoot = dirname(__DIR__);
$mainVendor = tests_resolve_main_vendor($worktreeRoot);

// Load the shared autoloader
$loader = require $mainVendor.'/autoload.php';

// Strip App\* and Database\* entries from the compiled classmap via reflection.
// Without this, the classmap takes priority over PSR-4 and all App\* classes
// resolve to the main repo's app/ directory, ignoring worktree changes.
$ref = new ReflectionClass($loader);
$prop = $ref->getProperty('classMap');
$prop->setAccessible(true);

$classMap = $prop->getValue($loader);
$filtered = array_filter($classMap, static function (string $class): bool {
    return ! str_starts_with($class, 'App\\')
        && ! str_starts_with($class, 'Database\\')
        && ! str_starts_with($class, 'Tests\\');
}, ARRAY_FILTER_USE_KEY);
$prop->setValue($loader, $filtered);

// Redirect PSR-4 prefixes to the worktree directories.
// Must set more-specific sub-namespace prefixes first (PSR-4 uses longest-prefix wins),
// then the root prefix as fallback. Without the sub-namespace entries, autoload_psr4.php's
// pre-registered 'Database\\Seeders\\' and 'Database\\Factories\\' entries (pointing to
// the main repo) take precedence over our 'Database\\' root prefix.
$loader->setPsr4('App\\', [$worktreeRoot.'/app']);
$loader->setPsr4('Tests\\', [$worktreeRoot.'/tests']);
$loader->setPsr4('Database\\Seeders\\', [$worktreeRoot.'/database/seeders']);
$loader->setPsr4('Database\\Factories\\', [$worktreeRoot.'/database/factories']);
$loader->setPsr4('Database\\', [$worktreeRoot.'/database']);
