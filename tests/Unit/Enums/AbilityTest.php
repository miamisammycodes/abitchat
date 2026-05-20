<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Ability;
use PHPUnit\Framework\TestCase;

class AbilityTest extends TestCase
{
    public function test_enum_has_exactly_thirteen_cases(): void
    {
        $this->assertCount(13, Ability::cases());
    }

    public function test_super_admin_abilities_exist_with_expected_values(): void
    {
        $this->assertSame('view-admin-dashboard', Ability::ViewAdminDashboard->value);
        $this->assertSame('manage-tenant-as-admin', Ability::ManageTenantAsAdmin->value);
        $this->assertSame('manage-platform-settings', Ability::ManagePlatformSettings->value);
    }

    public function test_owner_only_abilities_exist_with_expected_values(): void
    {
        $this->assertSame('manage-billing', Ability::ManageBilling->value);
        $this->assertSame('manage-team', Ability::ManageTeam->value);
        $this->assertSame('manage-tenant-settings', Ability::ManageTenantSettings->value);
        $this->assertSame('delete-tenant', Ability::DeleteTenant->value);
    }

    public function test_manager_plus_abilities_exist_with_expected_values(): void
    {
        $this->assertSame('manage-knowledge-base', Ability::ManageKnowledgeBase->value);
        $this->assertSame('manage-integrations', Ability::ManageIntegrations->value);
        $this->assertSame('view-analytics-full', Ability::ViewAnalyticsFull->value);
    }

    public function test_agent_plus_abilities_exist_with_expected_values(): void
    {
        $this->assertSame('manage-conversations', Ability::ManageConversations->value);
        $this->assertSame('manage-leads', Ability::ManageLeads->value);
        $this->assertSame('view-dashboard', Ability::ViewDashboard->value);
    }

    public function test_manage_billing_slug_exists(): void
    {
        $values = array_map(fn (Ability $a) => $a->value, Ability::cases());
        $this->assertContains('manage-billing', $values);
    }

    public function test_all_values_are_kebab_case(): void
    {
        foreach (Ability::cases() as $ability) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(-[a-z][a-z0-9]*)*$/',
                $ability->value,
                "Ability value '{$ability->value}' is not kebab-case"
            );
        }
    }

    public function test_enum_can_be_instantiated_from_string_value(): void
    {
        $this->assertSame(Ability::ManageBilling, Ability::from('manage-billing'));
    }
}
