<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use App\AI\Dtos\RealtimeSessionToken;

/**
 * Everything the /studio/live browser page needs to open one realtime voice
 * session: the neutral {@see RealtimeSessionToken} (ephemeral secret + model +
 * voice + expiry), the hard time cap the browser must enforce, and the vendor
 * WebRTC SDP endpoint (sourced from config, never hardcoded in JS).
 *
 * No standing credential is ever part of this object.
 */
final readonly class StudioSession
{
    public function __construct(
        public RealtimeSessionToken $token,
        public int $sessionMaxSeconds,
        public string $webrtcUrl,
    ) {}

    /**
     * @return array{client_secret: string, expires_at: int, model: string, voice: string, session_max_seconds: int, webrtc_url: string}
     */
    public function toArray(): array
    {
        $token = $this->token->toArray();

        return [
            'client_secret' => $token['client_secret'],
            'expires_at' => $token['expires_at'],
            'model' => $token['model'],
            'voice' => $token['voice'],
            'session_max_seconds' => $this->sessionMaxSeconds,
            'webrtc_url' => $this->webrtcUrl,
        ];
    }
}
