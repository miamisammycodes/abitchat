<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ClientSettingsRouteAbsentTest extends TestCase
{
    public function test_dashboard_settings_route_returns_404(): void
    {
        $this->actingAsTenantUser();
        $this->get('/dashboard/settings')->assertNotFound();
    }
}
