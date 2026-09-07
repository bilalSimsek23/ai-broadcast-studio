<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Dtos\GenerationParameters;
use InvalidArgumentException;

/**
 * A generation parameter (a caller override OR a value from
 * config('ai.text.models.*.parameters')) has the wrong type, is non-finite, or
 * is out of the centrally-defined range. Raw arbitrary parameter bags never
 * reach a provider.
 *
 * Messages never echo an arbitrary supplied key or value — only the fixed set
 * of supported parameter names and the numeric bounds.
 */
final class InvalidGenerationParameters extends InvalidArgumentException
{
    public static function temperatureOutOfRange(): self
    {
        return new self(sprintf(
            'temperature must be a finite number between %s and %s.',
            GenerationParameters::MIN_TEMPERATURE,
            GenerationParameters::MAX_TEMPERATURE,
        ));
    }

    public static function maxOutputTokensOutOfRange(): self
    {
        return new self(sprintf(
            'max_output_tokens must be an integer between %d and %d.',
            GenerationParameters::MIN_OUTPUT_TOKENS,
            GenerationParameters::MAX_OUTPUT_TOKENS,
        ));
    }

    public static function notANumber(string $field): self
    {
        return new self(sprintf('Generation parameter [%s] must be a finite number.', self::safeField($field)));
    }

    public static function notAnInteger(string $field): self
    {
        return new self(sprintf('Generation parameter [%s] must be an integer.', self::safeField($field)));
    }

    public static function unsupported(): self
    {
        return new self(sprintf(
            'An unsupported generation parameter was supplied. Supported parameters: %s.',
            implode(', ', GenerationParameters::SUPPORTED),
        ));
    }

    /**
     * Only ever called with one of the fixed supported names, but guard anyway.
     */
    private static function safeField(string $field): string
    {
        return in_array($field, GenerationParameters::SUPPORTED, true) ? $field : SafeIdentifier::REDACTED;
    }
}
