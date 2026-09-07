<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Exceptions\ProviderException;

/**
 * A {@see TextGenerationProvider} that always fails the way a real adapter
 * would when the vendor is unreachable / rejecting — used to prove the
 * rehearsal UI degrades to a safe notification instead of surfacing the error.
 */
final class ThrowingTextProvider implements TextGenerationProvider
{
    public function generate(ResolvedTextGenerationRequest $request): TextGenerationResponse
    {
        throw ProviderException::malformedResponse();
    }
}
