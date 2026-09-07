<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Exceptions\SafeIdentifier;
use PHPUnit\Framework\TestCase;

class SafeIdentifierTest extends TestCase
{
    public function test_a_short_plain_identifier_passes_through(): void
    {
        $this->assertSame('default', SafeIdentifier::format('default'));
        $this->assertSame('host_rebuttal', SafeIdentifier::format('host_rebuttal'));
        $this->assertSame('fake-balanced-v1', SafeIdentifier::format('fake-balanced-v1'));
    }

    public function test_an_over_long_value_is_redacted(): void
    {
        $long = str_repeat('a', 65);

        $this->assertSame(SafeIdentifier::REDACTED, SafeIdentifier::format($long));
    }

    public function test_a_value_with_unexpected_characters_is_redacted(): void
    {
        foreach (['has space', 'https://api.example.com/v1', 'a/b', 'x:y', "line\nbreak", ''] as $value) {
            $this->assertSame(SafeIdentifier::REDACTED, SafeIdentifier::format($value));
        }
    }
}
