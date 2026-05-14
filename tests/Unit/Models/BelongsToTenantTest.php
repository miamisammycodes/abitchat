<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureModel(): Model
    {
        // Uses the existing `leads` table (tenant_id NOT NULL) so no extra migration is needed.
        return new class extends Model
        {
            use BelongsToTenant;

            protected $table = 'leads';

            protected $fillable = ['tenant_id', 'name', 'email'];
        };
    }

    public function test_for_tenant_scope_filters_by_tenant_model(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $fixture = $this->fixtureModel();
        $fixture::create(['tenant_id' => $tenantA->id, 'name' => 'A1', 'email' => 'a1@example.com']);
        $fixture::create(['tenant_id' => $tenantA->id, 'name' => 'A2', 'email' => 'a2@example.com']);
        $fixture::create(['tenant_id' => $tenantB->id, 'name' => 'B1', 'email' => 'b1@example.com']);

        $aOnly = $fixture::query()->forTenant($tenantA)->get();
        $this->assertCount(2, $aOnly);
        $this->assertEqualsCanonicalizing(['A1', 'A2'], $aOnly->pluck('name')->all());
    }

    public function test_for_tenant_scope_filters_by_int_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $fixture = $this->fixtureModel();
        $fixture::create(['tenant_id' => $tenantA->id, 'name' => 'A1', 'email' => 'a1@example.com']);
        $fixture::create(['tenant_id' => $tenantB->id, 'name' => 'B1', 'email' => 'b1@example.com']);

        $aOnly = $fixture::query()->forTenant($tenantA->id)->get();
        $this->assertCount(1, $aOnly);
        $this->assertSame('A1', $aOnly->first()->name);
    }

    public function test_boot_hook_stamps_tenant_id_when_authed_user_has_one(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Auth::login($user);

        $fixture = $this->fixtureModel();
        $row = $fixture::create(['name' => 'NoTenantPassed', 'email' => 'no-tenant@example.com']);

        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_boot_hook_does_not_overwrite_explicit_tenant_id(): void
    {
        $tenantAuth = Tenant::factory()->create();
        $tenantTarget = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenantAuth->id]);

        Auth::login($user);

        $fixture = $this->fixtureModel();
        $row = $fixture::create(['tenant_id' => $tenantTarget->id, 'name' => 'Explicit', 'email' => 'x@example.com']);

        $this->assertSame($tenantTarget->id, $row->tenant_id);
    }

    public function test_boot_hook_does_nothing_when_no_authed_user(): void
    {
        $tenant = Tenant::factory()->create();
        $fixture = $this->fixtureModel();

        // No Auth::login. tenant_id is NOT NULL on `leads`, so we pass it explicitly
        // and assert the hook didn't try to overwrite or interfere.
        $row = $fixture::create(['tenant_id' => $tenant->id, 'name' => 'NoAuth', 'email' => 'na@example.com']);

        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_boot_hook_does_nothing_when_authed_user_has_no_tenant(): void
    {
        $userWithoutTenant = User::factory()->create(['tenant_id' => null]);
        Auth::login($userWithoutTenant);

        $tenant = Tenant::factory()->create();
        $fixture = $this->fixtureModel();
        $row = $fixture::create(['tenant_id' => $tenant->id, 'name' => 'AuthNoTenant', 'email' => 'ant@example.com']);

        // Hook leaves the explicit tenant_id alone (since user has none).
        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_tenant_relation_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $fixture = $this->fixtureModel();
        $row = $fixture::create(['tenant_id' => $tenant->id, 'name' => 'Rel', 'email' => 'rel@example.com']);

        $this->assertInstanceOf(Tenant::class, $row->tenant);
        $this->assertSame($tenant->id, $row->tenant->id);
    }
}
