<?php

use App\Http\Controllers\Api\V1\Widget\ChatController;
use App\Http\Controllers\Api\V1\Widget\LeadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// Widget endpoints (public, API key auth, rate limited, domain restricted)
Route::prefix('v1/widget')->middleware(['throttle:widget', 'validate.widget.domain'])->group(function () {
    Route::post('/init', [ChatController::class, 'init']);

    Route::middleware('widget.session_token')->group(function () {
        Route::post('/conversation', [ChatController::class, 'startConversation'])->middleware('check.limits:conversations');
        Route::post('/message', [ChatController::class, 'sendMessage'])->middleware('check.limits:tokens');
        Route::post('/message/stream', [ChatController::class, 'streamMessage'])->middleware('check.limits:tokens');
        Route::post('/conversation/end', [ChatController::class, 'endConversation']);
        Route::post('/lead', [LeadController::class, 'capture'])->middleware('check.limits:leads');
    });

    // Preflight: Laravel rejects OPTIONS on POST-only routes with 405 before
    // middleware mounts. This catch-all lets preflight reach ValidateWidgetDomain,
    // which short-circuits with a 204 + CORS headers. The closure is a fallback
    // and should not execute under normal flow.
    Route::options('{any}', fn () => response('', 204))->where('any', '.*');
});

// Client API endpoints (authenticated)
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    // TODO: Add client API routes
});
