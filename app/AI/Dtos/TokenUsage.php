<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * Vendor-neutral token accounting for a single generation. All fields are
 * optional because not every provider reports them. No cost / billing logic
 * lives here — only counts.
 */
final readonly class TokenUsage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {
        if ($inputTokens !== null && $inputTokens < 0) {
            throw new InvalidArgumentException('inputTokens cannot be negative.');
        }

        if ($outputTokens !== null && $outputTokens < 0) {
            throw new InvalidArgumentException('outputTokens cannot be negative.');
        }
    }

    public static function unknown(): self
    {
        return new self;
    }

    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null || $this->outputTokens === null) {
            return null;
        }

        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
        ];
    }
}
