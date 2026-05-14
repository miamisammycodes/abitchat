<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeItemStatus;
use App\Exceptions\InvalidTransitionException;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;

/**
 * Canonical state transitions for KnowledgeItem.
 *
 * PS-strict: each transition enforces the allowed source state(s) and
 * throws InvalidTransitionException on violation. Idempotent on
 * destination — markFailed on an already-Failed item succeeds and
 * overwrites the prior error_message (G-4 in the master spec).
 *
 * Owns markReady's side-effect of invalidating the retrieval cache so
 * newly-ready chunks become queryable immediately (G-5).
 */
class KnowledgeItemWorkflow
{
    public function __construct(private KnowledgeCache $cache) {}

    /** Pending|Failed → Processing. Clears prior error context. */
    public function markProcessing(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Pending, KnowledgeItemStatus::Failed],
            KnowledgeItemStatus::Processing,
        );

        $item->forceFill([
            'status' => KnowledgeItemStatus::Processing,
            'error_message' => null,
            'failed_at' => null,
        ])->save();
    }

    /** Processing → Ready. Invalidates retrieval cache for the tenant. */
    public function markReady(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Processing],
            KnowledgeItemStatus::Ready,
        );

        $item->forceFill(['status' => KnowledgeItemStatus::Ready])->save();

        $item->loadMissing('tenant');
        $this->cache->invalidate($item->tenant);
    }

    /** Any non-Ready → Failed. Captures throwable message + timestamp. */
    public function markFailed(KnowledgeItem $item, \Throwable $exception): void
    {
        $this->assertSourceNotIn(
            $item,
            [KnowledgeItemStatus::Ready],
            KnowledgeItemStatus::Failed,
        );

        $item->forceFill([
            'status' => KnowledgeItemStatus::Failed,
            'error_message' => $exception->getMessage(),
            'failed_at' => now(),
        ])->save();
    }

    /** Failed → Pending + dispatch ProcessKnowledgeItem. Clears error context. */
    public function retry(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Failed],
            KnowledgeItemStatus::Pending,
        );

        $item->forceFill([
            'status' => KnowledgeItemStatus::Pending,
            'error_message' => null,
            'failed_at' => null,
        ])->save();

        ProcessKnowledgeItem::dispatch($item);
    }

    /** @param  array<int, KnowledgeItemStatus>  $allowed */
    private function assertSourceIn(KnowledgeItem $item, array $allowed, KnowledgeItemStatus $target): void
    {
        if (! in_array($item->status, $allowed, true)) {
            throw new InvalidTransitionException(sprintf(
                'KnowledgeItem #%d cannot transition %s → %s (allowed sources: %s)',
                $item->id,
                $item->status->value,
                $target->value,
                implode(', ', array_map(fn (KnowledgeItemStatus $s) => $s->value, $allowed)),
            ));
        }
    }

    /** @param  array<int, KnowledgeItemStatus>  $forbidden */
    private function assertSourceNotIn(KnowledgeItem $item, array $forbidden, KnowledgeItemStatus $target): void
    {
        if (in_array($item->status, $forbidden, true)) {
            throw new InvalidTransitionException(sprintf(
                'KnowledgeItem #%d cannot transition %s → %s (forbidden source)',
                $item->id,
                $item->status->value,
                $target->value,
            ));
        }
    }
}
