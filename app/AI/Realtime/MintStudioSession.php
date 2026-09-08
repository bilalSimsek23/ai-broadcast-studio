<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Dtos\RealtimeSessionRequest;
use Illuminate\Contracts\Config\Repository;

/**
 * The one thin application service behind the /studio/live "Bağlan" action:
 * read the standing studio brief + limits from config, ask the configured
 * {@see RealtimeVoiceProvider} for an ephemeral session, and return a
 * {@see StudioSession} for the controller to hand to the browser.
 *
 * It never touches the API key and knows nothing about HTTP — the provider
 * owns the vendor call, this owns the studio policy (brief, time cap).
 */
final readonly class MintStudioSession
{
    private const FALLBACK_INSTRUCTIONS = 'Sen Türkçe konuşan bir yapay zekâ tartışma partnerisin. Kısa ve net konuş.';

    private const FALLBACK_WEBRTC_URL = 'https://api.openai.com/v1/realtime/calls';

    /** Mirrors the config('ai.realtime.session_max_seconds') default (20 min). */
    private const DEFAULT_SECONDS = 1200;

    private const MIN_SECONDS = 30;

    /** 60 min — headroom for 30–40 min broadcast rehearsals set via config. */
    private const MAX_SECONDS = 3600;

    public function __construct(
        private RealtimeVoiceProvider $provider,
        private Repository $config,
    ) {}

    public function __invoke(): StudioSession
    {
        $instructions = $this->config->get('ai.realtime.instructions');
        $instructions = is_string($instructions) && trim($instructions) !== ''
            ? $instructions
            : self::FALLBACK_INSTRUCTIONS;

        $token = $this->provider->createClientSession(new RealtimeSessionRequest($instructions));

        $maxSeconds = $this->config->get('ai.realtime.session_max_seconds');
        $maxSeconds = is_int($maxSeconds) ? $maxSeconds : self::DEFAULT_SECONDS;
        $maxSeconds = max(self::MIN_SECONDS, min($maxSeconds, self::MAX_SECONDS));

        $webrtcUrl = $this->config->get('ai.realtime.webrtc_url');
        $webrtcUrl = is_string($webrtcUrl) && trim($webrtcUrl) !== ''
            ? $webrtcUrl
            : self::FALLBACK_WEBRTC_URL;

        return new StudioSession($token, $maxSeconds, $webrtcUrl, $this->audioConstraints());
    }

    /**
     * getUserMedia audio constraints for the browser — sourced from config so
     * the frontend carries no literals. Defaults on (studio setup).
     *
     * @return array{echoCancellation: bool, noiseSuppression: bool, autoGainControl: bool}
     */
    private function audioConstraints(): array
    {
        $raw = $this->config->get('ai.realtime.audio.constraints');
        $raw = is_array($raw) ? $raw : [];

        return [
            'echoCancellation' => ($raw['echoCancellation'] ?? true) !== false,
            'noiseSuppression' => ($raw['noiseSuppression'] ?? true) !== false,
            'autoGainControl' => ($raw['autoGainControl'] ?? true) !== false,
        ];
    }
}
