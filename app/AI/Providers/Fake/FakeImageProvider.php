<?php

declare(strict_types=1);

namespace App\AI\Providers\Fake;

use App\AI\Contracts\ImageGenerationProvider;
use App\AI\Dtos\GeneratedImage;
use App\AI\Dtos\ImageGenerationRequest;

/**
 * A deterministic, network-free {@see ImageGenerationProvider} for local
 * development and tests. It lives in the application namespace (not the test
 * namespace) so it can be the configured default driver.
 *
 * It returns a real, visible SVG placeholder ("PROVA GÖRSELİ" + the requested
 * size) so the control page preview and the broadcast screen show something
 * recognisable end to end without a paid API call — not a 1x1 pixel that looks
 * like a rendering glitch.
 */
final class FakeImageProvider implements ImageGenerationProvider
{
    /** @var list<ImageGenerationRequest> */
    private array $calls = [];

    public function generate(ImageGenerationRequest $request): GeneratedImage
    {
        $this->calls[] = $request;

        return new GeneratedImage('image/svg+xml', $this->placeholder($request->size), $request->size);
    }

    private function placeholder(string $size): string
    {
        $label = htmlspecialchars($size, ENT_QUOTES);

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="1536" height="1024" viewBox="0 0 1536 1024">
              <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#1e3a5f"/><stop offset="1" stop-color="#0b1622"/>
              </linearGradient></defs>
              <rect width="1536" height="1024" fill="url(#g)"/>
              <text x="768" y="500" fill="#cfe3ff" font-family="sans-serif" font-size="72" font-weight="700" text-anchor="middle">PROVA GÖRSELİ</text>
              <text x="768" y="586" fill="#8fb4d9" font-family="sans-serif" font-size="38" text-anchor="middle">{$label} · sahte sürücü</text>
            </svg>
            SVG;

        return base64_encode($svg);
    }

    /**
     * @return list<ImageGenerationRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastCall(): ?ImageGenerationRequest
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)];
    }

    public function reset(): self
    {
        $this->calls = [];

        return $this;
    }
}
