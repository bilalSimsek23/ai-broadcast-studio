<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderException;
use App\AI\Realtime\MintStudioSession;
use App\AI\Realtime\ResolveStudioEpisode;
use App\AI\Realtime\StudioEpisodeUnavailable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The /studio/live realtime voice prototype: a full-screen page where a studio
 * host and the AI hold an uninterrupted Turkish spoken conversation.
 *
 * Thin by design — it renders the page and mints ephemeral sessions. Which
 * Ready episode + persona a session is bound to is validated in
 * {@see ResolveStudioEpisode}; the instructions are built in
 * {@see MintStudioSession}; the vendor call is in the configured
 * RealtimeVoiceProvider. Nothing here ever sees the API key.
 */
final class StudioLiveController extends Controller
{
    public function show(): View
    {
        return view('studio.live', [
            'sessionEndpoint' => route('studio.live.session', [], absolute: false),
        ]);
    }

    public function session(Request $request, MintStudioSession $mint, ResolveStudioEpisode $resolve): JsonResponse
    {
        $voice = $this->stringOrNull($request->input('voice'));
        $episodeUuid = $this->stringOrNull($request->input('episode'));
        $personaUuid = $this->stringOrNull($request->input('persona'));
        $maxSeconds = $this->intOrNull($request->input('max_seconds'));

        // Normal production flow: an episode is bound. A standalone (no-episode)
        // session is a development / diagnostic escape hatch only, off by
        // default — a wrong or missing episode must NOT quietly put a generic
        // assistant on live TV.
        if ($episodeUuid === null && ! (bool) config('ai.realtime.allow_standalone_session', false)) {
            return $this->refused('episode_required', 'Canlı yayın için önce bir "Yayına Hazır" bölüm seçin.');
        }

        $context = null;

        if ($episodeUuid !== null) {
            try {
                $context = $resolve($episodeUuid, $personaUuid);
            } catch (StudioEpisodeUnavailable $e) {
                return $this->refused($e->reason, $e->getMessage());
            }
        }

        try {
            $session = $mint($voice, $context, $maxSeconds);
        } catch (ProviderException $e) {
            report($e);

            return response()->json([
                'error' => 'realtime_unavailable',
                'message' => 'Canlı ses oturumu başlatılamadı. Lütfen tekrar deneyin.',
            ], 503);
        }

        return response()->json($session->toArray());
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * `0` is a real value here (no session limit) — only a missing / non-numeric
     * value becomes null.
     */
    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function refused(string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], 422);
    }
}
