<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CrawlUrlBlocklist;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlUrlBlocklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $row = CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);

        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_tenant_url_pair_is_unique(): void
    {
        $tenant = Tenant::factory()->create();
        CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);

        $this->expectException(QueryException::class);

        CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);
    }
}
