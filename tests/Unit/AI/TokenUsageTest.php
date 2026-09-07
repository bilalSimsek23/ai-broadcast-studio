<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Dtos\TokenUsage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TokenUsageTest extends TestCase
{
    public function test_total_is_the_sum_when_both_halves_are_known(): void
    {
        $usage = new TokenUsage(inputTokens: 10, outputTokens: 5);

        $this->assertSame(15, $usage->totalTokens());
    }

    public function test_total_is_unknown_when_either_half_is_missing(): void
    {
        $this->assertNull((new TokenUsage(inputTokens: 10))->totalTokens());
        $this->assertNull((new TokenUsage(outputTokens: 5))->totalTokens());
        $this->assertNull(TokenUsage::unknown()->totalTokens());
    }

    public function test_negative_counts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenUsage(inputTokens: -1);
    }

    public function test_the_array_shape_uses_vendor_neutral_keys_only(): void
    {
        $keys = array_keys((new TokenUsage(inputTokens: 1, outputTokens: 2))->toArray());

        // Neutral names only — never a vendor's "prompt_tokens" / "completion_tokens".
        $this->assertSame(['input_tokens', 'output_tokens', 'total_tokens'], $keys);
    }
}
