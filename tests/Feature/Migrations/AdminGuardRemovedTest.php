<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminGuardRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_table_does_not_exist(): void
    {
        $this->assertFalse(Schema::hasTable('admin_users'));
    }
}
