<?php

use App\Http\Controllers\StudioImageController;
use App\Http\Controllers\StudioLiveController;
use App\Http\Controllers\StudioLiveRelayController;
use App\Http\Middleware\EnsureStudioOperator;
use App\Http\Middleware\StudioBroadcastAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Studio live realtime voice (TASK-0007 / TASK-0009).
 *
 * The broadcast OUTPUT screen (GET /studio/live) is PUBLIC so it can be opened
 * as a capture source (vMix web input, OBS, a spare monitor) without a login
 * prompt. It renders only the orb and holds NO credential.
 *
 * The screen may run REMOTELY / in a separate browser, so it reaches its
 * endpoints via `StudioBroadcastAccess` (an authenticated admin OR the shared
 * `STUDIO_LIVE_ACCESS_TOKEN`). Endpoints that only the operator console uses
 * stay `EnsureStudioOperator` (admin only). Commands + state flow through the
 * cache-backed relay; audio never touches the server.
 */
Route::get('studio/live', [StudioLiveController::class, 'show'])
    ->middleware('throttle:300,1')
    ->name('studio.live');

// Reachable by the (possibly remote) broadcast screen — admin OR access token.
Route::middleware(StudioBroadcastAccess::class)
    ->prefix('studio/live')
    ->name('studio.live.')
    ->group(function (): void {
        Route::post('session', [StudioLiveController::class, 'session'])
            ->middleware('throttle:30,1')
            ->name('session');
        // Poll endpoints — tiny cache reads/writes; several screens may poll at
        // once. Generous ceilings that still stop abuse.
        Route::get('control', [StudioLiveRelayController::class, 'readControl'])
            ->middleware('throttle:600,1')
            ->name('control.read');
        Route::post('state', [StudioLiveRelayController::class, 'writeState'])
            ->middleware('throttle:1200,1')
            ->name('state.write');
        // Readable by the token too: a display-only screen (vMix web input)
        // polls it for the orb amplitude. Nothing sensitive in the state doc.
        Route::get('state', [StudioLiveRelayController::class, 'readState'])
            ->middleware('throttle:1200,1')
            ->name('state.read');
        // Single-owner claim for the AUDIO ENGINE screen: opening one elsewhere
        // is offered a takeover.
        Route::post('claim', [StudioLiveRelayController::class, 'claim'])
            ->middleware('throttle:600,1')
            ->name('claim');
    });

// Operator console only — admin session required.
Route::middleware(EnsureStudioOperator::class)
    ->prefix('studio')
    ->name('studio.')
    ->group(function (): void {
        Route::post('live/control', [StudioLiveRelayController::class, 'writeControl'])
            ->middleware('throttle:600,1')
            ->name('live.control.write');

        // Operator-generated broadcast still image. `generate` dispatches a
        // queued job and returns a ticket.
        Route::post('image', [StudioImageController::class, 'generate'])
            ->middleware('throttle:6,1')
            ->name('image');
    });

// Image result fetch — the broadcast screen pulls the bytes once by ticket.
Route::middleware(StudioBroadcastAccess::class)
    ->get('studio/image/{ticket}', [StudioImageController::class, 'status'])
    ->whereUuid('ticket')
    ->middleware('throttle:120,1')
    ->name('studio.image.status');
