<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Enums\KnowledgeItemStatus;
use App\Exceptions\InvalidTransitionException;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeCache;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class KnowledgeItemWorkflowTest extends TestCase
{
    private function makeItem(string $status = 'pending'): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Wf Co',
            'slug' => 'wf-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Wf Item',
            'content' => 'Lorem ipsum content for testing the workflow.',
            'status' => $status,
        ]);
    }

    private function workflow(?KnowledgeCache $cache = null): KnowledgeItemWorkflow
    {
        return new KnowledgeItemWorkflow($cache ?? new KnowledgeCache);
    }

    public function test_mark_processing_from_pending(): void
    {
        $item = $this->makeItem('pending');

        $this->workflow()->markProcessing($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Processing, $item->status);
    }

    public function test_mark_processing_from_failed_clears_error_context(): void
    {
        $item = $this->makeItem('failed');
        $item->forceFill(['error_message' => 'old failure', 'failed_at' => now()->subHour()])->save();

        $this->workflow()->markProcessing($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Processing, $item->status);
        $this->assertNull($item->error_message);
        $this->assertNull($item->failed_at);
    }

    public function test_mark_processing_from_ready_throws(): void
    {
        $item = $this->makeItem('ready');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markProcessing($item);
    }

    public function test_mark_processing_from_processing_throws(): void
    {
        $item = $this->makeItem('processing');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markProcessing($item);
    }

    public function test_mark_ready_from_processing_invalidates_cache(): void
    {
        $item = $this->makeItem('processing');

        $cache = Mockery::mock(KnowledgeCache::class);
        $cache->shouldReceive('invalidate')
            ->once()
            ->with(Mockery::on(fn ($t) => $t->id === $item->tenant_id));

        $this->workflow($cache)->markReady($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Ready, $item->status);
    }

    public function test_mark_ready_from_pending_throws(): void
    {
        $item = $this->makeItem('pending');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markReady($item);
    }

    public function test_mark_ready_from_failed_throws(): void
    {
        $item = $this->makeItem('failed');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markReady($item);
    }

    public function test_mark_failed_captures_error_and_timestamp(): void
    {
        $item = $this->makeItem('processing');
        $exception = new \RuntimeException('embedding service down');

        $this->workflow()->markFailed($item, $exception);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Failed, $item->status);
        $this->assertSame('embedding service down', $item->error_message);
        $this->assertNotNull($item->failed_at);
    }

    public function test_mark_failed_from_pending_works(): void
    {
        $item = $this->makeItem('pending');

        $this->workflow()->markFailed($item, new \RuntimeException('boom'));

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Failed, $item->status);
    }

    public function test_mark_failed_from_failed_overwrites_previous_error(): void
    {
        // G-4: ProcessKnowledgeItem::failed() AND GenerateEmbeddings::failed()
        // can both fire for the same item. Latest failure wins.
        $item = $this->makeItem('failed');
        $item->forceFill(['error_message' => 'first failure'])->save();

        $this->workflow()->markFailed($item, new \RuntimeException('second failure'));

        $item->refresh();
        $this->assertSame('second failure', $item->error_message);
    }

    public function test_mark_failed_from_ready_throws(): void
    {
        $item = $this->makeItem('ready');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markFailed($item, new \RuntimeException('boom'));
    }

    public function test_retry_from_failed_clears_error_and_dispatches_job(): void
    {
        Bus::fake();
        $item = $this->makeItem('failed');
        $item->forceFill(['error_message' => 'will be cleared', 'failed_at' => now()->subHour()])->save();

        $this->workflow()->retry($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Pending, $item->status);
        $this->assertNull($item->error_message);
        $this->assertNull($item->failed_at);

        Bus::assertDispatched(
            ProcessKnowledgeItem::class,
            fn (ProcessKnowledgeItem $job) => $job->item->id === $item->id,
        );
    }

    public function test_retry_from_ready_throws(): void
    {
        $item = $this->makeItem('ready');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->retry($item);
    }

    public function test_retry_from_pending_throws(): void
    {
        $item = $this->makeItem('pending');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->retry($item);
    }

    public function test_mark_skipped_no_content_from_processing(): void
    {
        $item = $this->makeItem('processing');

        $this->workflow()->markSkippedNoContent($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
    }

    public function test_mark_skipped_no_content_from_pending_throws(): void
    {
        $item = $this->makeItem('pending');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markSkippedNoContent($item);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
