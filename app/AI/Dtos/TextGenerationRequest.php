<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * The application-facing request. Callers name a LOGICAL model key only; the
 * concrete provider, vendor model id and default parameters are resolved by
 * App\AI\GenerateText via App\AI\Resolution\LogicalModelResolver.
 *
 * `parameters`, when present, is a set of caller OVERRIDES — a non-null field
 * wins over the logical model's configured default (see
 * GenerationParameters::mergedWith()).
 */
final readonly class TextGenerationRequest
{
    public function __construct(
        public string $model,
        public MessageList $messages,
        public ?string $systemInstructions = null,
        public ?GenerationParameters $parameters = null,
    ) {
        if (trim($model) === '') {
            throw new InvalidArgumentException('A text generation request needs a logical model key.');
        }

        if ($systemInstructions !== null && trim($systemInstructions) === '') {
            throw new InvalidArgumentException('System instructions, when provided, must not be blank.');
        }
    }
}
