<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderException;
use App\AI\Realtime\MintStudioSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The /studio/live realtime voice prototype: a full-screen page where a studio
 * host and the AI hold an uninterrupted Turkish spoken conversation.
 *
 * Thin by design — it renders the page and mints ephemeral sessions; all
 * realtime policy lives in {@see MintStudioSession} and the vendor call in the
 * configured RealtimeVoiceProvider. Nothing here ever sees the API key.
 */
final class StudioLiveController extends Controller
{
    public function show(): View
    {
        return view('studio.live', [
            'sessionEndpoint' => route('studio.live.session', [], absolute: false),
        ]);
    }

    public function session(Request $request, MintStudioSession $mint): JsonResponse
    {
        $voice = $request->input('voice');
        $voice = is_string($voice) && $voice !== '' ? $voice : null;

        try {
            // The voice is validated against config('ai.realtime.voices') inside
            // the service; an unknown value falls back to the default.
            $session = $mint($voice);
        } catch (ProviderException $e) {
            report($e);

            return response()->json([
                'error' => 'realtime_unavailable',
                'message' => 'Canlı ses oturumu başlatılamadı. Lütfen tekrar deneyin.',
            ], 503);
        }

        return response()->json($session->toArray());
    }
}
