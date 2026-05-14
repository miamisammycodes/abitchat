<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\PHPStan\NoRawTenantIdWhere;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoRawTenantIdWhere> */
class NoRawTenantIdWhereTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoRawTenantIdWhere;
    }

    public function test_flags_unqualified_tenant_id_where(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/raw_tenant_id_where_fixture.php'],
            [
                ["Raw where('tenant_id', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.", 15],
                ["Raw whereIn('tenant_id', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.", 20],
                // PHP-Parser anchors a chained MethodCall AST at the chain-start line.
                ["Raw where('leads.tenant_id', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.", 25],
            ],
        );
    }

    public function test_does_not_flag_safe_queries(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/safe_wheres_fixture.php'],
            [], // zero errors expected
        );
    }
}
