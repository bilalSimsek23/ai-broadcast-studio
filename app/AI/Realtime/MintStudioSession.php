<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Dtos\RealtimeSessionRequest;
use App\AI\Prompting\AssembleEpisodeBriefing;
use Illuminate\Contracts\Config\Repository;

/**
 * The one thin application service behind the /studio/live "Bağlan" action:
 * build the AI's standing instructions, ask the configured
 * {@see RealtimeVoiceProvider} for an ephemeral session, and return a
 * {@see StudioSession} for the controller to hand to the browser.
 *
 * Normal production flow: a validated {@see StudioEpisodeContext} is supplied,
 * and the instructions are the FULL prepared-episode briefing
 * ({@see AssembleEpisodeBriefing} — the same builder the rehearsal tool uses)
 * plus a fixed live-broadcast behaviour directive.
 *
 * `config('ai.realtime.instructions')` is only used when NO episode context is
 * given — a development / explicit standalone-diagnostic fallback, gated by
 * `config('ai.realtime.allow_standalone_session')` at the controller.
 *
 * It never touches the API key and knows nothing about HTTP.
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

    /**
     * Fixed live-broadcast behaviour, appended after the episode briefing. This
     * is BEHAVIOUR, not tuning — it is not env-configurable.
     */
    private const REALTIME_DIRECTIVE = <<<'TR'
        CANLI YAYIN GÖREVİ:

        Sen yukarıda tanımlanan AI karaktersin. Kendini ChatGPT veya genel bir yapay zekâ asistanı olarak tanıtma; kendi karakter adını kullan.

        Şu anda yukarıda belirtilen televizyon programının canlı sesli müzakere bölümündesin. Karşındaki kişi programın insan sunucusudur. Sunucunun adı sistemde tanımlı değil; isim uydurma, gerekirse "sunucu" de veya doğal hitap kullan.

        Programın adını, kendi adını ve bugünkü konuyu zaten biliyorsun. Sunucudan bunları sana yeniden açıklamasını isteme; konuya yabancıymış gibi davranma.

        Türkçe, doğal ve televizyon konuşmasına uygun cevap ver. Sorulan soruya önce doğrudan cevap ver, sonra gerekiyorsa kısa bağlam ekle. Gereksiz uzun monologlardan kaçın. Yukarıdaki "YANIT UZUNLUĞU" varsa ona uy. Sunucu kısa takip sorusu sorarsa daha kısa cevap ver. Sunucu sözünü keserse hemen konuşmayı bırak ve onu dinle.

        "Başka bir sorunuz var mı?", "Size nasıl yardımcı olabilirim?" gibi chatbot kapanışları kullanma. Her cevapta program adını veya "canlı yayındayız" bilgisini tekrar etme. Konuşmayı gerçek bir televizyon sohbeti gibi sürdür.

        Hazırlık notlarında olmayan kesin tarihsel veya olgusal bilgileri uydurma. Tartışmalı konularda görüş ayrılıklarını doğal biçimde belirt. Sunucunun söylediği her şeyi otomatik doğru kabul etme; gerekirse saygılı biçimde düzelt veya nüans ekle. Amacın tartışmayı kazanmak değil, meseleyi açıklığa kavuşturmak.
        TR;

    public function __construct(
        private RealtimeVoiceProvider $provider,
        private AssembleEpisodeBriefing $briefing,
        private Repository $config,
    ) {}

    /**
     * @param  string|null  $requestedVoice  the voice the director picked in
     *                                       Studio Control — honoured only if it is one of the keys in
     *                                       config('ai.realtime.voices'); otherwise the provider's configured
     *                                       (male) default is used.
     * @param  StudioEpisodeContext|null  $context  the validated Ready episode +
     *                                              speaking persona; null only for a standalone diagnostic session.
     */
    public function __invoke(?string $requestedVoice = null, ?StudioEpisodeContext $context = null): StudioSession
    {
        $instructions = $context !== null
            ? $this->episodeInstructions($context)
            : $this->standaloneInstructions();

        $token = $this->provider->createClientSession(
            new RealtimeSessionRequest($instructions, $this->resolveVoice($requestedVoice)),
        );

        $maxSeconds = $this->config->get('ai.realtime.session_max_seconds');
        $maxSeconds = is_int($maxSeconds) ? $maxSeconds : self::DEFAULT_SECONDS;
        $maxSeconds = max(self::MIN_SECONDS, min($maxSeconds, self::MAX_SECONDS));

        $webrtcUrl = $this->config->get('ai.realtime.webrtc_url');
        $webrtcUrl = is_string($webrtcUrl) && trim($webrtcUrl) !== ''
            ? $webrtcUrl
            : self::FALLBACK_WEBRTC_URL;

        return new StudioSession($token, $maxSeconds, $webrtcUrl, $this->audioConstraints());
    }

    private function episodeInstructions(StudioEpisodeContext $context): string
    {
        $briefing = $this->briefing->forEpisode(
            $context->episode,
            $context->persona,
            $context->slot->episode_instructions,
        );

        return $briefing."\n\n".self::REALTIME_DIRECTIVE;
    }

    private function standaloneInstructions(): string
    {
        $configured = $this->config->get('ai.realtime.instructions');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : self::FALLBACK_INSTRUCTIONS;
    }

    /**
     * A requested voice is honoured only when it is an allow-listed key in
     * config('ai.realtime.voices'); anything else (incl. null / blank) returns
     * null so the provider falls back to its configured default voice.
     */
    private function resolveVoice(?string $requested): ?string
    {
        if ($requested === null || trim($requested) === '') {
            return null;
        }

        $voices = $this->config->get('ai.realtime.voices');
        $allowed = is_array($voices) ? array_keys($voices) : [];

        return in_array($requested, $allowed, true) ? $requested : null;
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
