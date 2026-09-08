<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Dtos\RealtimeSessionToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RealtimeSessionTokenTest extends TestCase
{
    public function test_it_exposes_only_neutral_keys_and_no_standing_credential(): void
    {
        $token = new RealtimeSessionToken('ek_abc123', 1_900_000_000, 'gpt-realtime', 'marin');

        $this->assertSame(
            ['client_secret' => 'ek_abc123', 'expires_at' => 1_900_000_000, 'model' => 'gpt-realtime', 'voice' => 'marin'],
            $token->toArray(),
        );
        $this->assertArrayNotHasKey('api_key', $token->toArray());
    }

    public function test_it_rejects_an_empty_client_secret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RealtimeSessionToken('   ', 1_900_000_000, 'gpt-realtime', 'marin');
    }

    public function test_it_rejects_a_client_secret_that_looks_like_a_standing_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RealtimeSessionToken('sk-proj-not-an-ephemeral-secret', 1_900_000_000, 'gpt-realtime', 'marin');
    }

    public function test_it_rejects_a_non_positive_expiry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RealtimeSessionToken('ek_abc123', 0, 'gpt-realtime', 'marin');
    }

    public function test_it_rejects_a_blank_model_or_voice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RealtimeSessionToken('ek_abc123', 1_900_000_000, 'gpt-realtime', '  ');
    }
}
