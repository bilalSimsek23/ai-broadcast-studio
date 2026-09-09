<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderException;
use App\AI\Imaging\GenerateBroadcastImage;
use App\Models\Episode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /studio/image — the operator's "Görsel Oluştur" action on the Filament
 * "Canlı Yayın Kontrolü" page.
 *
 * Thin: validate, call {@see GenerateBroadcastImage}, return the image bytes as
 * a data: URI. Nothing is stored; the API key never reaches this class. The
 * generated image is NOT on air — the operator previews it and pushes it to
 * the broadcast screen from the browser over a same-origin BroadcastChannel.
 */
final class StudioImageController extends Controller
{
    public function generate(Request $request, GenerateBroadcastImage $generate): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:1000'],
            'size' => ['nullable', 'string', 'max:20'],
            'quality' => ['nullable', 'string', 'max:20'],
            'episode' => ['nullable', 'string', 'max:64'],
        ]);

        // The episode only enriches the prompt (program / title / main topic).
        // It is optional context, not a safety-critical binding — the operator
        // vets the result before it airs — so an unknown uuid is simply ignored.
        $episode = null;
        $episodeUuid = isset($data['episode']) && is_string($data['episode']) ? trim($data['episode']) : '';
        if ($episodeUuid !== '') {
            $episode = Episode::query()->where('uuid', $episodeUuid)->with('show')->first();
        }

        try {
            $image = $generate(
                (string) $data['prompt'],
                isset($data['size']) && is_string($data['size']) ? $data['size'] : null,
                $episode,
                isset($data['quality']) && is_string($data['quality']) ? $data['quality'] : null,
            );
        } catch (ProviderException $e) {
            report($e);

            return response()->json([
                'error' => 'image_unavailable',
                'message' => 'Görsel oluşturulamadı. Lütfen tekrar deneyin.',
            ], 503);
        }

        return response()->json($image->toArray());
    }
}
