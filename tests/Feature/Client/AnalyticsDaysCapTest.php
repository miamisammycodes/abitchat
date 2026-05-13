<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;

class AnalyticsDaysCapTest extends TestCase
{
    public function test_days_above_90_returns_validation_error(): void
    {
        $this->actingAsTenantUser();

        $response = $this->from('/analytics')->get('/analytics?days=91');
        $response->assertSessionHasErrors('days');
    }

    public function test_days_at_90_is_accepted(): void
    {
        $this->actingAsTenantUser();

        $response = $this->from('/analytics')->get('/analytics?days=90');
        $response->assertStatus(200);
        $response->assertSessionHasNoErrors();
    }

    public function test_default_days_is_used_when_omitted(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/analytics');
        $response->assertStatus(200);
        $response->assertSessionHasNoErrors();
    }

    public function test_days_below_1_returns_validation_error(): void
    {
        $this->actingAsTenantUser();

        $response = $this->from('/analytics')->get('/analytics?days=0');
        $response->assertSessionHasErrors('days');
    }
}
