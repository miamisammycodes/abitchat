<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class UpdateBotPersonalityValidationTest extends TestCase
{
    private User $admin;

    protected Tenant $tenantTarget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createSuperAdmin();
        $this->tenantTarget = Tenant::create([
            'name' => 'Tenant',
            'slug' => 'tenant',
            'status' => 'active',
        ]);
    }

    public function test_1000_chars_is_accepted(): void
    {
        $payload = [
            'bot_type' => 'support',
            'bot_tone' => 'friendly',
            'bot_custom_instructions' => str_repeat('a', 1000),
        ];

        $response = $this->actingAs($this->admin)
            ->put(route('admin.clients.update-bot-personality', $this->tenantTarget), $payload);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_1001_chars_is_rejected(): void
    {
        $payload = [
            'bot_type' => 'support',
            'bot_tone' => 'friendly',
            'bot_custom_instructions' => str_repeat('a', 1001),
        ];

        $response = $this->actingAs($this->admin)
            ->put(route('admin.clients.update-bot-personality', $this->tenantTarget), $payload);

        $response->assertRedirect();
        $response->assertSessionHasErrors('bot_custom_instructions');
    }

    public function test_null_instructions_is_accepted(): void
    {
        $payload = [
            'bot_type' => 'support',
            'bot_tone' => 'friendly',
            'bot_custom_instructions' => null,
        ];

        $response = $this->actingAs($this->admin)
            ->put(route('admin.clients.update-bot-personality', $this->tenantTarget), $payload);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }
}
