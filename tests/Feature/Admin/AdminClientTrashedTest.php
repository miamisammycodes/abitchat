<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Tenant;
use Tests\TestCase;

class AdminClientTrashedTest extends TestCase
{
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create([
            'name' => 'A', 'email' => 'a@test.example', 'password' => bcrypt('x'),
        ]);
    }

    public function test_index_hides_soft_deleted_tenants_by_default(): void
    {
        $active = Tenant::create([
            'name' => 'Active', 'slug' => 'active-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted = Tenant::create([
            'name' => 'Deleted', 'slug' => 'deleted-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/clients');
        $response->assertStatus(200);

        $clientIds = collect($response->viewData('page')['props']['clients']['data'])->pluck('id')->all();
        $this->assertContains($active->id, $clientIds);
        $this->assertNotContains($deleted->id, $clientIds);
    }

    public function test_index_shows_deleted_tenants_when_trashed_filter_is_with(): void
    {
        $active = Tenant::create([
            'name' => 'Active', 'slug' => 'active-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted = Tenant::create([
            'name' => 'Deleted', 'slug' => 'deleted-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/clients?trashed=with');
        $response->assertStatus(200);

        $clientIds = collect($response->viewData('page')['props']['clients']['data'])->pluck('id')->all();
        $this->assertContains($active->id, $clientIds);
        $this->assertContains($deleted->id, $clientIds);
    }

    public function test_index_shows_only_deleted_tenants_when_trashed_filter_is_only(): void
    {
        $active = Tenant::create([
            'name' => 'Active', 'slug' => 'active-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted = Tenant::create([
            'name' => 'Deleted', 'slug' => 'deleted-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/clients?trashed=only');
        $response->assertStatus(200);

        $clientIds = collect($response->viewData('page')['props']['clients']['data'])->pluck('id')->all();
        $this->assertNotContains($active->id, $clientIds);
        $this->assertContains($deleted->id, $clientIds);
    }

    public function test_admin_can_restore_a_soft_deleted_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Restoreme', 'slug' => 'restoreme-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $tenant->delete();

        $this->assertNotNull($tenant->fresh()->deleted_at);

        $response = $this->actingAs($this->admin, 'admin')
            ->post("/admin/clients/{$tenant->id}/restore");

        $response->assertRedirect();
        $this->assertNull(Tenant::withTrashed()->find($tenant->id)->deleted_at);
    }
}
