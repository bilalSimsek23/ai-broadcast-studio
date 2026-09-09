<?php

declare(strict_types=1);

namespace App\AI\Imaging;

use App\AI\Contracts\ImageGenerationProvider;
use App\AI\Dtos\GeneratedImage;
use App\AI\Dtos\ImageGenerationRequest;
use App\AI\Prompting\AssembleImagePrompt;
use App\Models\Episode;
use Illuminate\Contracts\Config\Repository;

/**
 * The one thin application service behind the "Görsel Oluştur" action on the
 * Filament "Canlı Yayın Kontrolü" page: assemble the prompt, resolve the pixel
 * size against the config allow-list, and ask the configured
 * {@see ImageGenerationProvider} for one image.
 *
 * It never touches the API key and knows nothing about HTTP. Nothing is
 * persisted: the returned {@see GeneratedImage} is handed to the browser as a
 * data: URI, previewed by the operator, and — on command — pushed to the
 * broadcast screen.
 */
final readonly class GenerateBroadcastImage
{
    private const FALLBACK_SIZE = '1536x1024';

    public function __construct(
        private ImageGenerationProvider $provider,
        private AssembleImagePrompt $prompt,
        private Repository $config,
    ) {}

    public function __invoke(string $operatorBrief, ?string $requestedSize = null, ?Episode $episode = null): GeneratedImage
    {
        return $this->provider->generate(new ImageGenerationRequest(
            $this->prompt->forOperatorBrief($operatorBrief, $episode),
            $this->resolveSize($requestedSize),
        ));
    }

    /**
     * A requested size is honoured only when it is an allow-listed key in
     * config('ai.image.sizes'); otherwise the configured default (then a fixed
     * fallback) is used.
     */
    private function resolveSize(?string $requested): string
    {
        $sizes = $this->config->get('ai.image.sizes');
        $allowed = is_array($sizes) ? array_keys($sizes) : [];

        if ($requested !== null && $requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }

        $default = $this->config->get('ai.image.size');
        if (is_string($default) && $default !== '' && ($allowed === [] || in_array($default, $allowed, true))) {
            return $default;
        }

        $first = $allowed[0] ?? self::FALLBACK_SIZE;

        return is_string($first) ? $first : self::FALLBACK_SIZE;
    }
}
