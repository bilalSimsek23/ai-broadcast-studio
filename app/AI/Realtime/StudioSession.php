<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use App\AI\Dtos\RealtimeSessionToken;

/**
 * Everything the /studio/live browser page needs to open one realtime voice
 * session: the neutral {@see RealtimeSessionToken} (ephemeral secret + model +
 * voice + expiry), the hard time cap the browser must enforce, the vendor
 * WebRTC SDP endpoint, and the getUserMedia audio constraints — all sourced
 * from config, never hardcoded in JS.
 *
 * No standing credential is ever part of this object.
 */
final readonly class StudioSession
{
    /**
     * @param  array{echoCancellation: bool, noiseSuppression: bool, autoGainControl: bool}  $audioConstraints
     */
    public function __construct(
        public RealtimeSessionToken $token,
        public int $sessionMaxSeconds,
        public string $webrtcUrl,
        public array $audioConstraints,
    ) {}

    /**
     * @return array{
     *     client_secret: string, expires_at: int, model: string, voice: string,
     *     session_max_seconds: int, webrtc_url: string,
     *     audio_constraints: array{echoCancellation: bool, noiseSuppression: bool, autoGainControl: bool}
     * }
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
            'audio_constraints' => $this->audioConstraints,
        ];
    }
}
