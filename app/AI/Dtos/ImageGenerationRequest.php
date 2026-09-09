<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * The vendor-neutral request to generate ONE broadcast still image: the fully
 * assembled prompt and the pixel size.
 *
 * Concrete model id, endpoint and credentials are the adapter's concern
 * (resolved from config) — they never appear here.
 */
final readonly class ImageGenerationRequest
{
    public function __construct(
        public string $prompt,
        public string $size,
    ) {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('An image generation request needs a non-empty prompt.');
        }

        if (trim($size) === '') {
            throw new InvalidArgumentException('An image generation request needs a non-empty size.');
        }
    }
}
