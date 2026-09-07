<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use App\AI\Exceptions\InvalidGenerationParameters;

/**
 * The intentionally small, centrally-validated generation parameter surface.
 *
 * Every value is optional. Types and ranges are checked HERE, once, so an
 * arbitrary unvalidated array never reaches a (future) vendor SDK.
 *
 * Precedence, applied by {@see mergedWith()}: a non-null value on the caller's
 * override wins over the logical-model default; where the override leaves a
 * field null, the default is kept.
 */
final readonly class GenerationParameters
{
    public const MIN_TEMPERATURE = 0.0;

    public const MAX_TEMPERATURE = 2.0;

    public const MIN_OUTPUT_TOKENS = 1;

    public const MAX_OUTPUT_TOKENS = 100_000;

    /** @var list<string> */
    public const SUPPORTED = ['temperature', 'max_output_tokens'];

    public function __construct(
        public ?float $temperature = null,
        public ?int $maxOutputTokens = null,
    ) {
        if ($temperature !== null
            && (! is_finite($temperature) || $temperature < self::MIN_TEMPERATURE || $temperature > self::MAX_TEMPERATURE)) {
            throw InvalidGenerationParameters::temperatureOutOfRange();
        }

        if ($maxOutputTokens !== null
            && ($maxOutputTokens < self::MIN_OUTPUT_TOKENS || $maxOutputTokens > self::MAX_OUTPUT_TOKENS)) {
            throw InvalidGenerationParameters::maxOutputTokensOutOfRange();
        }
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * Build from a raw config/array bag (e.g.
     * config('ai.text.models.*.parameters')). Unknown keys are rejected;
     * types are coerced only where unambiguous, otherwise rejected.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::SUPPORTED, true)) {
                throw InvalidGenerationParameters::unsupported();
            }
        }

        $temperature = $values['temperature'] ?? null;
        if ($temperature !== null && ! is_int($temperature) && ! is_float($temperature)) {
            throw InvalidGenerationParameters::notANumber('temperature');
        }
        // A non-finite float (NAN / INF) is still `is_float`; the constructor's
        // finiteness + range check below is what rejects it.

        $maxOutputTokens = $values['max_output_tokens'] ?? null;
        if ($maxOutputTokens !== null && ! is_int($maxOutputTokens)) {
            throw InvalidGenerationParameters::notAnInteger('max_output_tokens');
        }

        return new self(
            temperature: $temperature === null ? null : (float) $temperature,
            maxOutputTokens: $maxOutputTokens,
        );
    }

    /**
     * Return a new set: this instance's values, with any non-null value on
     * $overrides taking precedence.
     */
    public function mergedWith(?self $overrides): self
    {
        if ($overrides === null) {
            return $this;
        }

        return new self(
            temperature: $overrides->temperature ?? $this->temperature,
            maxOutputTokens: $overrides->maxOutputTokens ?? $this->maxOutputTokens,
        );
    }

    /**
     * Only the parameters that are actually set, under their neutral keys.
     *
     * @return array{temperature?: float, max_output_tokens?: int}
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->temperature !== null) {
            $out['temperature'] = $this->temperature;
        }

        if ($this->maxOutputTokens !== null) {
            $out['max_output_tokens'] = $this->maxOutputTokens;
        }

        return $out;
    }
}
