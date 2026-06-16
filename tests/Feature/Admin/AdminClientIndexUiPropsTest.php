<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class AdminClientIndexUiPropsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createSuperAdmin();
    }

    public function test_index_exposes_trashed_sort_and_direction_filters_to_the_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/clients?trashed=only&sort=name&direction=asc');
        $response->assertStatus(200);

        $filters = $response->viewData('page')['props']['filters'];
        $this->assertSame('only', $filters['trashed']);
        $this->assertSame('name', $filters['sort']);
        $this->assertSame('asc', $filters['direction']);
    }

    public function test_sort_by_name_ascending_orders_the_client_list(): void
    {
        Tenant::create([
            'name' => 'Zebra', 'slug' => 'zebra-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        Tenant::create([
            'name' => 'Alpha', 'slug' => 'alpha-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/clients?sort=name&direction=asc');
        $response->assertStatus(200);

        $names = collect($response->viewData('page')['props']['clients']['data'])->pluck('name')->all();
        $this->assertSame('Alpha', $names[0]);
        $this->assertSame('Zebra', $names[1]);
    }

    public function test_restore_route_round_trips_for_a_trashed_client(): void
    {
        $tenant = Tenant::create([
            'name' => 'ToRestore', 'slug' => 'torestore-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $tenant->delete();

        $this->actingAs($this->admin)
            ->post("/admin/clients/{$tenant->id}/restore")
            ->assertRedirect(route('admin.clients.show', $tenant->id));

        $this->assertNull(Tenant::withTrashed()->find($tenant->id)->deleted_at);
    }
}
