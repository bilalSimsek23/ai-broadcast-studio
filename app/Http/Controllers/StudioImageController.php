<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateBroadcastImageJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The operator "Görsel Oluştur" action on the Filament "Canlı Yayın Kontrolü"
 * page. Image generation runs as a queued job so a slow render never hits the
 * web request timeout:
 *
 *   POST /studio/image          -> validate, dispatch, return {ticket} (202)
 *   GET  /studio/image/{ticket} -> {status: pending|ready|failed|expired, ...}
 *
 * Nothing is persisted beyond a short-lived cache entry; the API key never
 * reaches this class. The generated image is NOT on air — the operator previews
 * it and pushes it to the broadcast screen from the browser.
 */
final class StudioImageController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:1000'],
            'size' => ['nullable', 'string', 'max:20'],
            'quality' => ['nullable', 'string', 'max:20'],
            'episode' => ['nullable', 'string', 'max:64'],
        ]);

        $ticket = (string) Str::uuid();

        Cache::put(GenerateBroadcastImageJob::cacheKey($ticket), ['status' => 'pending'], 600);

        GenerateBroadcastImageJob::dispatch(
            $ticket,
            (string) $data['prompt'],
            isset($data['size']) && is_string($data['size']) ? $data['size'] : null,
            isset($data['quality']) && is_string($data['quality']) ? $data['quality'] : null,
            isset($data['episode']) && is_string($data['episode']) ? trim($data['episode']) : null,
        );

        return response()->json(['ticket' => $ticket, 'status' => 'pending'], 202);
    }

    public function status(string $ticket): JsonResponse
    {
        $state = Cache::get(GenerateBroadcastImageJob::cacheKey($ticket));

        if (! is_array($state)) {
            return response()->json(['status' => 'expired'], 404);
        }

        return response()->json($state);
    }
}
