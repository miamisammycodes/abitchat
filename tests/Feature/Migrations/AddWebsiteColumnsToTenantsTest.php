<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddWebsiteColumnsToTenantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenants_table_has_website_url_and_auto_recrawl_columns(): void
    {
        $tenant = Tenant::factory()->create([
            'website_url' => 'https://example.com',
            'auto_recrawl' => false,
        ]);

        $this->assertSame('https://example.com', $tenant->fresh()->website_url);
        $this->assertFalse($tenant->fresh()->auto_recrawl);
    }

    public function test_website_url_is_nullable_and_auto_recrawl_defaults_true(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($tenant->website_url);
        $this->assertTrue($tenant->auto_recrawl);
    }
}
