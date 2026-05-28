<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;

/**
 * DatabaseSeeder must seed production-safe reference data ONLY — never users
 * or tenants (production cannot contain fake accounts).
 *
 * Extends Illuminate's TestCase (not the project TestCase) to avoid
 * RefreshDatabase's transaction wrapper: migrate:fresh issues DDL, which
 * causes implicit commits and cannot run inside a transaction.
 */
class DatabaseSeederTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Skip rollback on teardown — SQLite can't ALTER TABLE to reverse the
     * make_users_tenant_id_nullable migration, and the :memory: DB is discarded.
     */
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->artisan('db:seed');
    }

    public function test_seeds_the_three_reference_plans(): void
    {
        $this->assertSame(3, Plan::count());
    }

    public function test_seeds_no_users(): void
    {
        $this->assertSame(0, User::count());
    }

    public function test_seeds_no_tenants(): void
    {
        $this->assertSame(0, Tenant::count());
    }
}
