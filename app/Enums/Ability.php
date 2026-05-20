<?php

declare(strict_types=1);

namespace App\Enums;

enum Ability: string
{
    // SuperAdmin abilities
    case ViewAdminDashboard = 'view-admin-dashboard';
    case ManageTenantAsAdmin = 'manage-tenant-as-admin';
    case ManagePlatformSettings = 'manage-platform-settings';

    // Owner-only
    case ManageBilling = 'manage-billing';
    case ManageTeam = 'manage-team';
    case ManageTenantSettings = 'manage-tenant-settings';
    case DeleteTenant = 'delete-tenant';

    // Manager+
    case ManageKnowledgeBase = 'manage-knowledge-base';
    case ManageIntegrations = 'manage-integrations';
    case ViewAnalyticsFull = 'view-analytics-full';

    // Agent+
    case ManageConversations = 'manage-conversations';
    case ManageLeads = 'manage-leads';
    case ViewDashboard = 'view-dashboard';
}
