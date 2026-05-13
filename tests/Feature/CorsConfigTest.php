<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigTest extends TestCase
{
    public function test_cors_does_not_allow_wildcard_origin(): void
    {
        $origins = config('cors.allowed_origins');
        $this->assertIsArray($origins);
        $this->assertNotContains('*', $origins);
    }

    public function test_cors_includes_app_url(): void
    {
        $origins = config('cors.allowed_origins');
        $appUrl = rtrim((string) config('app.url'), '/');
        $this->assertContains($appUrl, $origins);
    }

    public function test_cors_paths_excludes_api_globally(): void
    {
        $paths = config('cors.paths');
        $this->assertNotContains('api/*', $paths, 'Widget CORS is owned by ValidateWidgetDomain; api/* must not be CORS-handled globally.');
    }

    public function test_cors_paths_still_cover_sanctum(): void
    {
        $this->assertContains('sanctum/csrf-cookie', config('cors.paths'));
    }
}
