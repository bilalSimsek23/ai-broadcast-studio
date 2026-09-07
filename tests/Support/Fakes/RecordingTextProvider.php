<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\ResponseMetadata;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Dtos\TokenUsage;
use App\AI\Enums\FinishReason;
use App\AI\GenerateText;

/**
 * A second, distinct {@see TextGenerationProvider} implementation used only to
 * prove that {@see GenerateText} routes to the provider bound to the
 * requested logical model — not always to the default fake. Registered as a
 * singleton in the container by the test so the recorded call can be read
 * back.
 */
final class RecordingTextProvider implements TextGenerationProvider
{
    /** @var list<ResolvedTextGenerationRequest> */
    public array $calls = [];

    public function generate(ResolvedTextGenerationRequest $request): TextGenerationResponse
    {
        $this->calls[] = $request;

        return new TextGenerationResponse(
            text: 'routed-to-recording-provider',
            metadata: new ResponseMetadata(
                logicalProvider: $request->logicalProvider,
                logicalModel: $request->logicalModel,
                providerModelId: $request->vendorModelId,
                finishReason: FinishReason::Stop,
            ),
            usage: TokenUsage::unknown(),
        );
    }
}
