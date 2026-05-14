<?php

// LINE-NUMBER-SENSITIVE: NoRawTenantIdWhereTest references specific line numbers
// in this file. Do not reformat, reorder, or add/remove lines without also
// updating the test's line-number assertions.

namespace Tests\Unit\Rules\Fixtures\RawTenantIdWhere;

use App\Models\Lead;

class RawTenantIdWhereFixture
{
    public function flagged(int $tenantId): mixed
    {
        return Lead::where('tenant_id', $tenantId)->get();         // line 15 — flagged
    }

    public function flaggedWhereIn(array $ids): mixed
    {
        return Lead::whereIn('tenant_id', $ids)->get();            // line 20 — flagged
    }

    public function flaggedQualified(int $tenantId): mixed
    {
        return Lead::join('users', 'users.id', 'leads.id')         // line 25 — flagged (chained MethodCall anchors at chain-start)
            ->where('leads.tenant_id', $tenantId)
            ->get();
    }
}
