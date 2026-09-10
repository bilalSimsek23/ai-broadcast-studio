<?php

use App\Http\Controllers\StudioImageController;
use App\Http\Controllers\StudioLiveController;
use App\Http\Middleware\EnsureStudioOperator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Studio live realtime voice prototype (TASK-0007).
 *
 * The broadcast OUTPUT screen (GET /studio/live) is PUBLIC so it can be opened
 * as a display / capture source (Remix, OBS, a spare monitor) without a login
 * prompt. It renders only the orb, holds NO credential, and does nothing on its
 * own — it acts only on commands from the operator console over a same-origin
 * BroadcastChannel. The credential-minting and image endpoints below stay
 * admin-gated and rate-limited.
 */
Route::get('studio/live', [StudioLiveController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('studio.live');

Route::middleware(EnsureStudioOperator::class)
    ->prefix('studio')
    ->name('studio.')
    ->group(function (): void {
        Route::post('live/session', [StudioLiveController::class, 'session'])
            ->middleware('throttle:12,1')
            ->name('live.session');

        // Operator-generated broadcast still image. `generate` dispatches a
        // queued job and returns a ticket; the browser polls `status`.
        Route::post('image', [StudioImageController::class, 'generate'])
            ->middleware('throttle:6,1')
            ->name('image');
        Route::get('image/{ticket}', [StudioImageController::class, 'status'])
            ->whereUuid('ticket')
            ->middleware('throttle:120,1')
            ->name('image.status');
    });
