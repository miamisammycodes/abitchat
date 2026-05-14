<?php

// LINE-NUMBER-SENSITIVE: the companion raw_tenant_id_where_fixture.php has
// line-number assertions in NoRawTenantIdWhereTest. This file expects zero
// errors regardless of line layout, but reformat with care to keep the pair
// readable side-by-side.

namespace Tests\Unit\Rules\Fixtures\SafeWheres;

use App\Models\Lead;
use App\Models\Tenant;

class SafeWheresFixture
{
    public function viaScope(Tenant $tenant): mixed
    {
        return Lead::forTenant($tenant)->get();
    }

    public function whereOnOtherColumn(int $score): mixed
    {
        return Lead::where('score', '>', $score)->get();
    }

    public function whereWithVariable(string $col, int $tenantId): mixed
    {
        // Rule must only flag literal 'tenant_id'; dynamic columns are out of scope.
        return Lead::where($col, $tenantId)->get();
    }
}
