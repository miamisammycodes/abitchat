<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Single owner of the retrieval result cache.
 *
 * Cache layout:
 * - Result key: knowledge:{tenant}:v{version}:{md5(query)} — holds an
 *   array of chunk strings for a (tenant, query) pair.
 * - Version key: knowledge_version:{tenant} — incremented to invalidate
 *   every result entry for the tenant in one atomic write. Existing
 *   v{N-1} keys remain in storage until TTL expiry; readers compute the
 *   current version on every get(), so they cannot accidentally hit them.
 *
 * Previously owned across RetrievalService (read path) and
 * KnowledgeBaseController::clearKnowledgeCache (write path). The two
 * touched the same key shape but neither was the source of truth.
 */
class KnowledgeCache
{
    /** Result-cache TTL, in seconds. */
    private const TTL_SECONDS = 600;

    /** @return array<int, string>|null */
    public function get(Tenant $tenant, string $query): ?array
    {
        $key = $this->resultKey($tenant, $query);

        $value = Cache::get($key);

        return is_array($value) ? $value : null;
    }

    /** @param  array<int, string>  $chunks */
    public function put(Tenant $tenant, string $query, array $chunks): void
    {
        Cache::put($this->resultKey($tenant, $query), $chunks, self::TTL_SECONDS);
    }

    public function invalidate(Tenant $tenant): void
    {
        Cache::increment($this->versionKey($tenant));
    }

    private function resultKey(Tenant $tenant, string $query): string
    {
        $version = Cache::get($this->versionKey($tenant), 0);

        return "knowledge:{$tenant->id}:v{$version}:".md5($query);
    }

    private function versionKey(Tenant $tenant): string
    {
        return "knowledge_version:{$tenant->id}";
    }
}
