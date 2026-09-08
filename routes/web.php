<?php

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
    });
