<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use App\AI\Enums\FinishReason;

/**
 * Application-useful metadata about a completed generation.
 *
 * The LOGICAL provider/model keys are the identifiers the rest of the app
 * should log, display or branch on. `providerModelId` is the concrete model
 * string the provider actually used — diagnostic only; it must never be
 * persisted onto a domain model or treated as a logical key (CLAUDE.md §5).
 */
final readonly class ResponseMetadata
{
    public function __construct(
        public string $logicalProvider,
        public string $logicalModel,
        public ?string $providerModelId = null,
        public ?FinishReason $finishReason = null,
    ) {}

    /**
     * @return array{logical_provider: string, logical_model: string, provider_model_id: string|null, finish_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'logical_provider' => $this->logicalProvider,
            'logical_model' => $this->logicalModel,
            'provider_model_id' => $this->providerModelId,
            'finish_reason' => $this->finishReason?->value,
        ];
    }
}
