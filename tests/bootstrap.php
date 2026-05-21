<?php

/**
 * Test bootstrap for git-worktree agents.
 *
 * Git worktrees share the parent repo's vendor/ directory.
 * Composer's classmap hard-codes paths pointing to the main repo's app/ dir.
 * This bootstrap strips all App\* and Database\* classmap entries so that
 * PSR-4 fallback resolves them from the worktree instead.
 */
$worktreeRoot = dirname(__DIR__);
$mainVendor = '/Users/sam/Dev/laravel/chatbot/vendor';

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
