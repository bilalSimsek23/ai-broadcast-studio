<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\TextGenerationRequest;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Resolution\LogicalModelResolver;

/**
 * The one thin application-level entry point for generating text.
 *
 *   logical model key
 *     → resolve provider + vendor model id + configured default parameters
 *     → merge caller overrides over those defaults (caller wins per field)
 *     → call the provider with a fully-resolved neutral request
 *     → return the provider's neutral response unchanged
 *
 * It knows nothing about Filament, and nothing yet about Episode, AiPersona,
 * readiness, or prompt assembly — those belong to later tasks.
 */
final readonly class GenerateText
{
    public function __construct(private LogicalModelResolver $resolver) {}

    public function generate(TextGenerationRequest $request): TextGenerationResponse
    {
        $model = $this->resolver->model($request->model);

        $parameters = $model->defaultParameters->mergedWith($request->parameters);

        $resolved = new ResolvedTextGenerationRequest(
            vendorModelId: $model->vendorModelId,
            logicalProvider: $model->logicalProvider,
            logicalModel: $model->logicalModel,
            messages: $request->messages,
            parameters: $parameters,
            systemInstructions: $request->systemInstructions,
        );

        return $model->provider->generate($resolved);
    }
}
