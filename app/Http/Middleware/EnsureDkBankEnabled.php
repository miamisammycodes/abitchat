<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side killswitch for the DK Bank QR payment flow.
 *
 * The DK controller reaches the live DK Bank APIs, so a disabled feature must be
 * unreachable — not just hidden in the UI. Aborts 404 (not 403) so a disabled
 * feature stays invisible: a guessed route name reveals nothing about its existence.
 */
class EnsureDkBankEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('services.dk_bank.enabled'), 404);

        return $next($request);
    }
}
