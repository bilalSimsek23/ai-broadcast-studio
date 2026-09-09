<?php

use App\Http\Controllers\StudioImageController;
use App\Http\Controllers\StudioLiveController;
use App\Http\Middleware\EnsureStudioOperator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Studio live realtime voice prototype (TASK-0007). Admin-gated. The session
 * endpoint mints a short-lived ephemeral OpenAI secret and is rate-limited.
 */
Route::middleware(EnsureStudioOperator::class)
    ->prefix('studio')
    ->name('studio.')
    ->group(function (): void {
        Route::get('live', [StudioLiveController::class, 'show'])->name('live');
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
