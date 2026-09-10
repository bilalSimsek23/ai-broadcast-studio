<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AI\Realtime\StudioLiveRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The server-side command/state relay for the ONE studio (see
 * {@see StudioLiveRelay}). Lets the broadcast screen run remotely / in a
 * separate browser: the console writes intent, the screen polls it and posts
 * back its state.
 *
 * Route gating (routes/web.php):
 *  - GET  control, POST state  -> StudioBroadcastAccess (admin OR access token)
 *  - POST control, GET  state  -> EnsureStudioOperator  (admin only)
 */
final class StudioLiveRelayController extends Controller
{
    public function readControl(StudioLiveRelay $relay): JsonResponse
    {
        return response()->json($relay->control());
    }

    public function writeControl(Request $request, StudioLiveRelay $relay): JsonResponse
    {
        $data = $request->validate([
            'desired' => ['nullable', 'in:connected,idle'],
            'muted' => ['nullable', 'boolean'],
            'voiceId' => ['nullable', 'string', 'max:40'],
            'inputId' => ['nullable', 'string', 'max:256'],
            'outputId' => ['nullable', 'string', 'max:256'],
            'episodeUuid' => ['nullable', 'string', 'max:64'],
            'personaUuid' => ['nullable', 'string', 'max:64'],
            'durationSeconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'image' => ['nullable', 'array'],
            'image.visible' => ['nullable', 'boolean'],
            'image.ticket' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($relay->putControl($data));
    }

    public function readState(StudioLiveRelay $relay): JsonResponse
    {
        return response()->json($relay->state());
    }

    public function writeState(Request $request, StudioLiveRelay $relay): JsonResponse
    {
        $data = $request->validate([
            'connected' => ['nullable', 'boolean'],
            'muted' => ['nullable', 'boolean'],
            'remainingSeconds' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:120'],
            'inputActive' => ['nullable', 'string', 'max:256'],
            'outputSupported' => ['nullable', 'boolean'],
            'deviceError' => ['nullable', 'boolean'],
            'deviceLost' => ['nullable', 'boolean'],
            'imageVisible' => ['nullable', 'boolean'],
        ]);

        $relay->putState($data);

        return response()->json(['ok' => true]);
    }
}
