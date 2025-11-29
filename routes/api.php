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

// Widget endpoints (public, API key auth)
Route::prefix('v1/widget')->group(function () {
    Route::post('/init', [ChatController::class, 'init']);
    Route::post('/conversation', [ChatController::class, 'startConversation']);
    Route::post('/message', [ChatController::class, 'sendMessage']);
    Route::get('/message/stream', [ChatController::class, 'streamMessage']);
    Route::post('/conversation/end', [ChatController::class, 'endConversation']);
    Route::post('/lead', [LeadController::class, 'capture']);
});

// Client API endpoints (authenticated)
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    // TODO: Add client API routes
});
