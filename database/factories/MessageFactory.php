<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => 'user',
            'content' => $this->faker->sentence(),
            'tokens_used' => 0,
        ];
    }

    public function fromAssistant(): self
    {
        return $this->state([
            'role' => 'assistant',
            'tokens_used' => $this->faker->numberBetween(50, 500),
        ]);
    }
}
