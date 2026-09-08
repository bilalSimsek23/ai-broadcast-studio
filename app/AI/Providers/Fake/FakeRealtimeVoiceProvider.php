<?php

declare(strict_types=1);

namespace App\AI\Providers\Fake;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Dtos\RealtimeSessionRequest;
use App\AI\Dtos\RealtimeSessionToken;
use Illuminate\Support\Carbon;

/**
 * A deterministic, network-free {@see RealtimeVoiceProvider} for local
 * development and tests. It lives in the application namespace (not the test
 * namespace) so it can be the configured default driver.
 *
 * It returns a syntactically valid, obviously-fake ephemeral secret (prefixed
 * `ek_fake_`, never `sk-`) so the /studio/live page and its backend can be
 * exercised end to end without a real OpenAI session — the browser simply will
 * not be able to complete the WebRTC handshake with it.
 */
final class FakeRealtimeVoiceProvider implements RealtimeVoiceProvider
{
    /** @var list<RealtimeSessionRequest> */
    private array $calls = [];

    public function createClientSession(RealtimeSessionRequest $request): RealtimeSessionToken
    {
        $this->calls[] = $request;

        $fingerprint = substr(hash('sha256', $request->instructions.'|'.($request->voiceOverride ?? '')), 0, 24);

        return new RealtimeSessionToken(
            clientSecret: 'ek_fake_'.$fingerprint,
            expiresAt: Carbon::now()->addMinute()->getTimestamp(),
            model: 'fake-realtime-v1',
            voice: $request->voiceOverride ?? 'fake-voice',
        );
    }

    /**
     * @return list<RealtimeSessionRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastCall(): ?RealtimeSessionRequest
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)];
    }

    public function reset(): self
    {
        $this->calls = [];

        return $this;
    }
}
