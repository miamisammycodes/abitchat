<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Enums\KnowledgeItemStatus;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeItemStatusStringTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_item_persists_skipped_no_content_status(): void
    {
        $tenant = Tenant::factory()->create();

        $item = KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'webpage',
            'title' => 'JS page',
            'status' => KnowledgeItemStatus::SkippedNoContent,
        ]);

        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->refresh()->status);
    }
}
