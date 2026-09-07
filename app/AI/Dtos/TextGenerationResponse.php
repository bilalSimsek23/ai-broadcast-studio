<?php

declare(strict_types=1);

namespace App\AI\Dtos;

/**
 * The vendor-neutral result of a text generation: the generated text, the
 * application-useful {@see ResponseMetadata}, and vendor-neutral
 * {@see TokenUsage}. This is what an App\AI\Contracts\TextGenerationProvider
 * returns and what App\AI\GenerateText hands back to the application.
 */
final readonly class TextGenerationResponse
{
    public function __construct(
        public string $text,
        public ResponseMetadata $metadata,
        public TokenUsage $usage,
    ) {}
}
