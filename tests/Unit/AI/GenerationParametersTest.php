<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Dtos\GenerationParameters;
use App\AI\Exceptions\InvalidGenerationParameters;
use PHPUnit\Framework\TestCase;

class GenerationParametersTest extends TestCase
{
    public function test_it_accepts_values_inside_the_supported_ranges(): void
    {
        $params = new GenerationParameters(temperature: 0.7, maxOutputTokens: 800);

        $this->assertSame(0.7, $params->temperature);
        $this->assertSame(800, $params->maxOutputTokens);
        $this->assertSame(['temperature' => 0.7, 'max_output_tokens' => 800], $params->toArray());
    }

    public function test_none_is_an_all_null_set(): void
    {
        $params = GenerationParameters::none();

        $this->assertNull($params->temperature);
        $this->assertNull($params->maxOutputTokens);
        $this->assertSame([], $params->toArray());
    }

    public function test_a_temperature_above_the_maximum_is_rejected(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        new GenerationParameters(temperature: 5.0);
    }

    public function test_a_negative_temperature_is_rejected(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        new GenerationParameters(temperature: -0.1);
    }

    public function test_a_zero_or_negative_max_output_tokens_is_rejected(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        new GenerationParameters(maxOutputTokens: 0);
    }

    public function test_an_absurd_max_output_tokens_is_rejected(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        new GenerationParameters(maxOutputTokens: GenerationParameters::MAX_OUTPUT_TOKENS + 1);
    }

    public function test_from_array_rejects_an_unsupported_key(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        GenerationParameters::fromArray(['top_p' => 0.9]);
    }

    public function test_non_finite_temperatures_are_rejected_by_the_constructor(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            try {
                new GenerationParameters(temperature: $value);
                $this->fail('expected rejection of a non-finite temperature');
            } catch (InvalidGenerationParameters) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_non_finite_temperatures_are_rejected_by_from_array(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            try {
                GenerationParameters::fromArray(['temperature' => $value]);
                $this->fail('expected rejection of a non-finite temperature');
            } catch (InvalidGenerationParameters) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_exception_message_never_echoes_the_offending_key_or_value(): void
    {
        $unexpectedKey = 'some-unexpected-parameter-name';

        try {
            GenerationParameters::fromArray([$unexpectedKey => 1]);
            $this->fail('expected rejection');
        } catch (InvalidGenerationParameters $e) {
            $this->assertStringNotContainsString($unexpectedKey, $e->getMessage());
        }
    }

    public function test_from_array_rejects_a_non_numeric_temperature(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        GenerationParameters::fromArray(['temperature' => 'hot']);
    }

    public function test_from_array_rejects_a_non_integer_max_output_tokens(): void
    {
        $this->expectException(InvalidGenerationParameters::class);

        GenerationParameters::fromArray(['max_output_tokens' => 12.5]);
    }

    public function test_from_array_coerces_an_integer_temperature_to_float(): void
    {
        $params = GenerationParameters::fromArray(['temperature' => 1]);

        $this->assertSame(1.0, $params->temperature);
    }

    public function test_merged_with_null_returns_the_same_values(): void
    {
        $defaults = new GenerationParameters(temperature: 0.7, maxOutputTokens: 800);

        $this->assertSame($defaults, $defaults->mergedWith(null));
    }

    public function test_merged_with_lets_a_non_null_override_win_field_by_field(): void
    {
        $defaults = new GenerationParameters(temperature: 0.7, maxOutputTokens: 800);
        $overrides = new GenerationParameters(temperature: 0.1);

        $merged = $defaults->mergedWith($overrides);

        // Override wins for the field it sets...
        $this->assertSame(0.1, $merged->temperature);
        // ...and the default is kept where the override is null.
        $this->assertSame(800, $merged->maxOutputTokens);
    }
}
