<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_enum_has_four_cases_with_expected_backing_values(): void
    {
        $this->assertSame('super_admin', Role::SuperAdmin->value);
        $this->assertSame('owner', Role::Owner->value);
        $this->assertSame('manager', Role::Manager->value);
        $this->assertSame('agent', Role::Agent->value);
    }

    public function test_enum_has_exactly_four_cases(): void
    {
        $this->assertCount(4, Role::cases());
    }

    public function test_rank_ordering_owner_highest_among_tenant_roles(): void
    {
        $this->assertGreaterThan(Role::Manager->rank(), Role::Owner->rank());
        $this->assertGreaterThan(Role::Agent->rank(), Role::Manager->rank());
        $this->assertGreaterThan(0, Role::Agent->rank());
    }

    public function test_rank_returns_expected_values(): void
    {
        $this->assertSame(3, Role::Owner->rank());
        $this->assertSame(2, Role::Manager->rank());
        $this->assertSame(1, Role::Agent->rank());
        $this->assertSame(0, Role::SuperAdmin->rank());
    }

    public function test_super_admin_rank_is_orthogonal_not_highest(): void
    {
        // SuperAdmin rank() returns 0 — it is orthogonal, not a "higher" tenant rank
        $this->assertSame(0, Role::SuperAdmin->rank());
        $this->assertLessThan(Role::Agent->rank(), Role::SuperAdmin->rank());
    }

    public function test_is_platform_level_returns_true_only_for_super_admin(): void
    {
        $this->assertTrue(Role::SuperAdmin->isPlatformLevel());
        $this->assertFalse(Role::Owner->isPlatformLevel());
        $this->assertFalse(Role::Manager->isPlatformLevel());
        $this->assertFalse(Role::Agent->isPlatformLevel());
    }

    public function test_label_returns_platform_admin_for_super_admin(): void
    {
        $this->assertSame('Platform Admin', Role::SuperAdmin->label());
    }

    public function test_label_never_returns_super_admin_string(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertStringNotContainsStringIgnoringCase('Super Admin', $role->label());
        }
    }

    public function test_label_returns_expected_values_for_tenant_roles(): void
    {
        $this->assertSame('Owner', Role::Owner->label());
        $this->assertSame('Manager', Role::Manager->label());
        $this->assertSame('Agent', Role::Agent->label());
    }

    public function test_enum_can_be_instantiated_from_string_value(): void
    {
        $this->assertSame(Role::Owner, Role::from('owner'));
        $this->assertSame(Role::SuperAdmin, Role::from('super_admin'));
    }
}
