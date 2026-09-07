<?php

declare(strict_types=1);

namespace App\AI\Resolution;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\GenerationParameters;

/**
 * The outcome of resolving one logical model key: the concrete provider to
 * call, the vendor model identifier to call it with, the configured default
 * parameters, and the logical keys that produced them (for telemetry).
 */
final readonly class ResolvedTextModel
{
    public function __construct(
        public TextGenerationProvider $provider,
        public string $logicalProvider,
        public string $logicalModel,
        public string $vendorModelId,
        public GenerationParameters $defaultParameters,
    ) {}
}
