<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddCrawlColumnsToKnowledgeItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_normalized_column_exists_and_is_writable(): void
    {
        $tenant = Tenant::factory()->create();
        $item = KnowledgeItem::factory()->forTenant($tenant)->create([
            'type' => 'webpage',
            'url_normalized' => 'https://example.com/about',
        ]);

        $this->assertSame('https://example.com/about', $item->fresh()->url_normalized);
    }

    public function test_composite_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->markTestSkipped('Index introspection assertion targets MySQL only.');
        }

        $indexes = DB::select('SHOW INDEXES FROM knowledge_items WHERE Key_name = ?', ['kn_items_tenant_type_norm_idx']);
        $this->assertNotEmpty($indexes);
    }
}
