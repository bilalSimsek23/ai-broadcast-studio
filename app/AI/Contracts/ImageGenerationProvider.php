<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Dtos\GeneratedImage;
use App\AI\Dtos\ImageGenerationRequest;
use App\AI\Exceptions\ProviderException;

/**
 * Capability: turn a text prompt into ONE still image for the broadcast screen.
 * Product/domain code depends on THIS interface, never on a vendor.
 *
 * Implementations must be time-bounded and must translate every vendor error
 * into {@see ProviderException} (or a subtype) — a raw HTTP exception or a raw
 * vendor payload never escapes the adapter. The API key is never logged,
 * echoed, or placed in an exception, and never appears in the result.
 */
interface ImageGenerationProvider
{
    /**
     * @throws ProviderException
     */
    public function generate(ImageGenerationRequest $request): GeneratedImage;
}
