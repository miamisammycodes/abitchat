<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KnowledgeItemStatus;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeItem>
 */
class KnowledgeItemFactory extends Factory
{
    protected $model = KnowledgeItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(4),
            'type' => 'text',
            'content' => fake()->paragraph(),
            'status' => KnowledgeItemStatus::Ready,
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function webpage(string $url, string $normalized): self
    {
        return $this->state([
            'type' => 'webpage',
            'source_url' => $url,
            'url_normalized' => $normalized,
        ]);
    }
}
