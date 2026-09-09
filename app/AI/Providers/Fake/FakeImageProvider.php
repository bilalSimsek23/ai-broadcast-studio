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
 * It returns a real, decodable 1x1 PNG — enough for the control page to render
 * a preview and for the broadcast screen to display something end to end
 * without a paid API call.
 */
final class FakeImageProvider implements ImageGenerationProvider
{
    /** A valid, fully decodable 1x1 transparent PNG. */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var list<ImageGenerationRequest> */
    private array $calls = [];

    public function generate(ImageGenerationRequest $request): GeneratedImage
    {
        $this->calls[] = $request;

        return new GeneratedImage('image/png', self::PNG_1PX, $request->size);
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
