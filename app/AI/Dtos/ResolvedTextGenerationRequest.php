<?php

declare(strict_types=1);

namespace App\AI\Dtos;

/**
 * The provider-facing request: what App\AI\GenerateText passes to a
 * App\AI\Contracts\TextGenerationProvider after resolution.
 *
 * `vendorModelId` is the concrete model identifier the adapter should call.
 * `logicalProvider` / `logicalModel` are carried purely so the provider can
 * echo them into {@see ResponseMetadata} / telemetry — a provider never needs
 * them to do its job. `parameters` is already merged and validated.
 */
final readonly class ResolvedTextGenerationRequest
{
    public function __construct(
        public string $vendorModelId,
        public string $logicalProvider,
        public string $logicalModel,
        public MessageList $messages,
        public GenerationParameters $parameters,
        public ?string $systemInstructions = null,
    ) {}
}
