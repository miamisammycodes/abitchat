<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'session_id' => $this->faker->uuid(),
            'status' => 'active',
            'metadata' => [
                'user_agent' => $this->faker->userAgent(),
                'ip' => $this->faker->ipv4(),
            ],
        ];
    }

    public function closed(): self
    {
        return $this->state(['status' => 'closed']);
    }

    public function archived(): self
    {
        return $this->state(['status' => 'archived']);
    }
}
