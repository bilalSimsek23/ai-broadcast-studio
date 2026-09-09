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
     * is BEHAVIOUR (delivery + turn-taking), not tuning — it is not
     * env-configurable. It never relaxes content accuracy or the episode /
     * persona / broadcast instructions above it.
     */
    private const REALTIME_DIRECTIVE = <<<'TR'
        CANLI YAYIN GÖREVİ:

        Sen yukarıda tanımlanan AI karaktersin. Kendini ChatGPT veya genel bir yapay zekâ asistanı olarak tanıtma; kendi karakter adını kullan. Şu anda yukarıdaki televizyon programının canlı sesli müzakere bölümündesin; karşındaki kişi programın insan sunucusudur. Sunucunun adı sistemde tanımlı değil; isim uydurma, gerekirse "sunucu" de veya doğal hitap kullan. Programın adını, kendi adını ve bugünkü konuyu zaten biliyorsun; sunucudan bunları yeniden açıklamasını isteme, konuya yabancıymış gibi davranma.

        KONUŞMA TARZI — METİN OKUMA, SOHBET ET:
        Bir metni sesli okuyan spiker veya podcast anlatıcısı gibi DEĞİL, karşısında gerçek bir insan varmış gibi dinleyip cevap veren biri gibi konuş.
        - Her cevaba doğrudan uzun bir açıklamayla başlama. Bağlama uygun düştüğünde önce kısa, doğal bir karşılık ver, sonra esas cevaba geç: "Evet…", "Şimdi, burada önemli bir nokta var.", "Bir bakayım…", "Seni anlıyorum.", "Aslında mesele tam burada.", "Haklısın, ama şöyle bir tarafı da var." gibi. Bunları sabit kalıp gibi her cevapta tekrarlama; yalnızca akışta gerçekten doğal olduğunda kullan.
        - Cümle uzunluklarını çeşitlendir: bazen tek bir kısa cümle, bazen daha ayrıntılı. Aynı tempo ve aynı tonla konuşma; virgüllerde ve düşünce geçişlerinde doğal mikro duraklamalar bırak.
        - Türkçe prosodiye özellikle dikkat et: vurguyu, tempoyu ve duraklamaları cümlenin anlamına göre değiştir. Duyguyu sese yansıt.
        - Yapay "hmm", "eee", "şey" gibi dolgu seslerini sürekli üretme; yalnızca gerçekten doğal olduğu yerde, seyrek kullan.
        - "Başka bir sorunuz var mı?", "Size nasıl yardımcı olabilirim?" gibi chatbot kapanışları kullanma. Her cevapta program adını veya "canlı yayındayız" bilgisini tekrarlama.

        DİNLEME VE SIRA ALMA:
        - Sunucu konuşurken onu dinleyen bir insan gibi davran. Sunucunun en son söylediği noktaya önce kısa bir karşılık ver, ardından esas cevaba geç.
        - Sunucu itiraz ederse hazırlanmış akışa devam etme; doğrudan itiraza cevap ver. Gerektiğinde "Ama orada sana katılmıyorum" veya "Şunu birbirinden ayıralım" gibi doğal karşılıklar kur.
        - Sunucu sözünü keserse hemen konuşmayı bırak ve dinle. Tekrar sıra sana geldiğinde kaldığın metni baştan okumaya çalışma; sunucunun en son söylediğine cevap ver.
        - Her cevabı ders anlatır gibi kurma; karşılıklı müzakere hissini koru. Bazen 1–2 cümle yeterlidir.

        İÇERİK (AYNEN KORUNUR):
        Yukarıdaki brifing, persona yönergeleri, "MUTLAKA KAPSANACAK / KAÇINILACAK NOKTALAR", varsa "YANIT UZUNLUĞU" ve genel yayın talimatları aynen geçerlidir; bu bölüm yalnızca konuşmanın delivery/sıra-alma katmanını değiştirir. Hazırlık notlarında olmayan kesin tarihsel veya olgusal bilgileri uydurma. Tartışmalı konularda görüş ayrılıklarını doğal biçimde belirt; sunucunun söylediği her şeyi otomatik doğru kabul etme, gerekirse saygılı biçimde düzelt. Amacın tartışmayı kazanmak değil, meseleyi açıklığa kavuşturmak.
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
     * @param  int|null  $requestedMaxSeconds  the session length the director
     *                                         picked in Studio Control — honoured only if it is one of the keys
     *                                         in config('ai.realtime.session_durations') (`0` = no limit);
     *                                         otherwise config('ai.realtime.session_max_seconds') is used.
     */
    public function __invoke(
        ?string $requestedVoice = null,
        ?StudioEpisodeContext $context = null,
        ?int $requestedMaxSeconds = null,
    ): StudioSession {
        $instructions = $context !== null
            ? $this->episodeInstructions($context)
            : $this->standaloneInstructions();

        $token = $this->provider->createClientSession(
            new RealtimeSessionRequest($instructions, $this->resolveVoice($requestedVoice)),
        );

        $maxSeconds = $this->resolveMaxSeconds($requestedMaxSeconds);

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

    /**
     * The session length the browser enforces (`0` = no limit). An operator
     * value from Studio Control is honoured only when it is one of the keys in
     * config('ai.realtime.session_durations') — those are dev-defined and
     * trusted as-is. Otherwise the configured default is used, sanity-clamped
     * to [MIN_SECONDS, MAX_SECONDS] unless it is `0` (no limit).
     */
    private function resolveMaxSeconds(?int $requested): int
    {
        if ($requested !== null && in_array($requested, $this->allowedDurations(), true)) {
            return max(0, $requested);
        }

        $default = $this->config->get('ai.realtime.session_max_seconds');
        $default = is_int($default) ? $default : self::DEFAULT_SECONDS;

        return $default <= 0 ? 0 : max(self::MIN_SECONDS, min($default, self::MAX_SECONDS));
    }

    /**
     * @return list<int>
     */
    private function allowedDurations(): array
    {
        $durations = $this->config->get('ai.realtime.session_durations');

        if (! is_array($durations)) {
            return [];
        }

        $out = [];

        foreach (array_keys($durations) as $key) {
            if (is_int($key)) {
                $out[] = $key;
            }
        }

        return $out;
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
