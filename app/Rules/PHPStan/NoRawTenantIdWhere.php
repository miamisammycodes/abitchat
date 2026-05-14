<?php

declare(strict_types=1);

namespace App\Rules\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids raw `where('tenant_id', ...)` / `whereIn('tenant_id', ...)` /
 * qualified `where('leads.tenant_id', ...)` patterns. Use the
 * `forTenant($tenant)` scope provided by `App\Models\Concerns\BelongsToTenant`.
 *
 * Scope-by-design: matches Eloquent-style `where*` Method/StaticCall nodes
 * with a string-literal first argument equal to 'tenant_id' or '*.tenant_id'.
 * Covers both `$builder->where('tenant_id', ...)` (MethodCall) and
 * `Model::where('tenant_id', ...)` (StaticCall, magic-forwarded to the
 * builder by Eloquent). Does NOT cover `DB::table(...)->where('tenant_id', ...)`
 * — those sites are converted to Eloquent in Cluster B.
 *
 * @implements Rule<CallLike>
 */
class NoRawTenantIdWhere implements Rule
{
    /** @var array<int, string> */
    private const WHERE_METHODS = [
        'where',
        'orWhere',
        'whereIn',
        'orWhereIn',
        'whereNotIn',
        'orWhereNotIn',
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        if (! in_array($methodName, self::WHERE_METHODS, true)) {
            return [];
        }

        if (! isset($node->args[0]) || ! $node->args[0] instanceof Node\Arg) {
            return [];
        }

        $firstArg = $node->args[0]->value;
        if (! $firstArg instanceof String_) {
            return [];
        }

        $column = $firstArg->value;
        if ($column !== 'tenant_id' && ! str_ends_with($column, '.tenant_id')) {
            return [];
        }

        // Self-exempt the trait file itself — its scope method must be raw.
        if (str_ends_with($scope->getFile(), 'BelongsToTenant.php')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "Raw %s('%s', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.",
                $methodName,
                $column,
            ))->identifier('tenancy.rawTenantId')->build(),
        ];
    }
}
