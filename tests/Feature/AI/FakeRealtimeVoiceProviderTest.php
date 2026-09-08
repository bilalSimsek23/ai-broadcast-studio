<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Dtos\RealtimeSessionRequest;
use App\AI\Providers\Fake\FakeRealtimeVoiceProvider;
use PHPUnit\Framework\TestCase;

class FakeRealtimeVoiceProviderTest extends TestCase
{
    public function test_it_implements_the_contract(): void
    {
        $this->assertInstanceOf(RealtimeVoiceProvider::class, new FakeRealtimeVoiceProvider);
    }

    public function test_it_returns_an_obviously_fake_ephemeral_secret_never_a_standing_key(): void
    {
        $token = (new FakeRealtimeVoiceProvider)->createClientSession(new RealtimeSessionRequest('Türkçe konuş.'));

        $this->assertStringStartsWith('ek_fake_', $token->clientSecret);
        $this->assertStringNotContainsString('sk-', $token->clientSecret);
        $this->assertGreaterThan(time(), $token->expiresAt);
    }

    public function test_it_is_deterministic_for_the_same_request(): void
    {
        $fake = new FakeRealtimeVoiceProvider;

        $a = $fake->createClientSession(new RealtimeSessionRequest('aynı brief'));
        $b = $fake->createClientSession(new RealtimeSessionRequest('aynı brief'));

        $this->assertSame($a->clientSecret, $b->clientSecret);
    }

    public function test_it_records_calls_and_applies_a_voice_override(): void
    {
        $fake = new FakeRealtimeVoiceProvider;

        $token = $fake->createClientSession(new RealtimeSessionRequest('brief', voiceOverride: 'cedar'));

        $this->assertSame(1, $fake->callCount());
        $this->assertSame('cedar', $token->voice);
        $this->assertSame('brief', $fake->lastCall()?->instructions);
    }
}
