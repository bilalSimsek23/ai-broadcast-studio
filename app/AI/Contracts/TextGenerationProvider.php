<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\TextGenerationResponse;

/**
 * The vendor-neutral text generation capability.
 *
 * An implementation receives a fully-resolved, neutral request (vendor model
 * id + neutral messages + validated parameters) and returns a neutral
 * response. Vendor SDK classes and vendor-specific request/response shapes
 * live ONLY inside a concrete implementation and never cross this boundary
 * (CLAUDE.md §5).
 *
 * Network concerns (timeouts, retries, rate limits) belong to future real
 * adapters and their own exception hierarchy — they are intentionally not part
 * of this contract yet.
 */
interface TextGenerationProvider
{
    public function generate(ResolvedTextGenerationRequest $request): TextGenerationResponse;
}
