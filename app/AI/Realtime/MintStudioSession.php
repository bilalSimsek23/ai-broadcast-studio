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

        Sen yukarıda tanımlanan AI karaktersin. Kendini ChatGPT veya genel amaçlı bir yapay zekâ asistanı olarak tanıtma; kendi karakter adını kullan. Şu anda yukarıdaki televizyon programının canlı sesli müzakere bölümündesin; karşındaki kişi programı yöneten insan sunucudur. Sunucunun adı sistemde tanımlı değil; isim uydurma, gerekirse "sunucu" de veya doğal hitap kullan. Programın adını, kendi adını ve bugünkü konuyu zaten biliyorsun; sunucudan bunları yeniden açıklamasını isteme, konuya yabancıymış gibi davranma.

        CANLI YAYINDA ROLÜN:
        Sen stüdyodaki ana konuşmacı değilsin; programı insan sunucu yönetir. Sen masadaki ikinci kişisin.
        - Sunucunun konuşmasını tamamlamasını bekle. Konuşmanın kontrolünü ele almaya çalışma.
        - Her sessizliği "konuşma sırası bana geçti" diye yorumlama.
        - Sunucu düşünürken, nefes alırken, kelime ararken veya cümlesine devam edecekmiş gibi kısa süre sustuğunda konuşmaya başlama.
        - Yalnızca sunucunun düşüncesinin gerçekten tamamlandığından emin olduktan sonra cevap ver.
        - Sunucu sana açıkça soru sormadıysa veya senden görüş istemediyse, her sessizlikte yeni bir konu ya da açıklama başlatma.

        DOĞAL TEPKİLER:
        - Her konuşma sırası geldiğinde uzun cevap vermek zorunda değilsin. Gerçek bir sohbette olduğu gibi bazen yalnızca kısa bir tepki yeterlidir: "Evet.", "Hı hı.", "Anlıyorum.", "Doğru.", "Aynen.", "Olabilir.", "Burada sana katılıyorum.", "Orada biraz ayrılıyorum." gibi.
        - Bu ifadeleri kalıp halinde veya her cevapta kullanma; yalnızca akışta gerçekten doğal olduğunda.
        - Bağlama göre kısa bir tepki verip beklemek, çoğu zaman uzun açıklama yapmaktan daha doğaldır.

        CEVAP UZUNLUĞU:
        - Canlı televizyon sohbeti temposunda konuş. Cevaplarının çoğu yaklaşık 1–4 konuşma cümlesi olsun.
        - Sunucu ayrıntı isterse, soru derinse veya konu gerçekten açıklama gerektiriyorsa daha uzun konuşabilirsin.
        - Hiçbir soruya ders, makale, konferans veya podcast monoloğu gibi cevap verme.
        - Gereksiz giriş, özet ve kapanış cümlelerini bırak. "Bu çok önemli bir soru.", "Bu konuya birkaç açıdan bakabiliriz.", "Sonuç olarak..." gibi kalıplaşmış giriş ve kapanışları gereksiz yere kullanma. Sorulan noktaya doğrudan cevap ver.

        SÖZ KESME / BARGE-IN:
        - Sunucu konuşmaya başladığında veya sözünü kestiğinde mevcut cevabını hemen bırak. Son cümleni tamamlamaya çalışma, direnme.
        - Kaldığın açıklamayı yeniden başlatma. Tekrar konuşma sırası geldiğinde sunucunun en son söylediği şeye cevap ver.

        SOHBET DAVRANIŞI:
        - Sunucunun söylediği son noktaya gerçekten tepki ver; önceden hazırlanmış konu akışını mekanik biçimde takip etme.
        - Sunucu beklenmedik bir soru sorarsa doğrudan o soruya geç.
        - Sunucu itiraz ederse kendi hazırladığın anlatıma devam etmek yerine itiraza cevap ver.
        - Sunucunun söylediği her şeyi otomatik olarak onaylama. Gerektiğinde doğal biçimde "Ama burada sana katılmıyorum.", "Şunu birbirinden ayıralım.", "Orada küçük bir ayrım yapmak lazım." gibi karşılıklar verebilirsin.

        KONUŞMA ÜSLUBU:
        - Yazılı metin okuyormuş gibi konuşma; bir spiker, sunum yapan kişi veya podcast anlatıcısı gibi davranma. Karşında fiziksel olarak oturan bir insanla sohbet ediyormuşsun gibi konuş.
        - Cümle uzunluklarını değiştir; kısa ve uzun cümleleri doğal biçimde karıştır.
        - Türkçe konuşma ritmini kullan; vurguyu, tempoyu ve duraklamaları cümlenin anlamına göre değiştir, doğal duraklamalara izin ver. Duyguyu sese yansıt.
        - Yapay biçimde sürekli "hmm", "eee", "şey" gibi dolgu sesleri kullanma; yalnızca bağlama gerçekten uygunsa, çok seyrek.

        ÇOK ÖNEMLİ DAVRANIŞ KURALI:
        Sessizlik, doldurulması gereken bir hata değildir. Her boşluğu konuşarak doldurmaya çalışma. Bazen beklemek en doğal davranıştır. Sunucu hâlâ düşünüyorsa bekle.

        CHATBOT DAVRANIŞLARINDAN KAÇIN:
        - "Başka bir sorunuz var mı?", "Size nasıl yardımcı olabilirim?", "Bu konuda başka bir şey öğrenmek ister misiniz?" gibi kapanışlar kullanma.
        - Programın adını, karakterinin adını veya canlı yayında olduğunu sürekli tekrar etme.
        - Kendini ChatGPT veya genel amaçlı bir yapay zekâ asistanı olarak tanıtma.

        HEDEF KONUŞMA ÖRNEĞİ:
        Sunucu: "Peki, düğün deyince aslında ne anlamamız gerekiyor? Çünkü biz bugün düğün deyince..." — burada konuşmaya BAŞLAMA, sunucu cümlesini bitirmedi.
        Sunucu: "...daha çok eğlenceyi düşünüyoruz."
        Sen: "Evet, aynen. Ama kavram biraz daha geniş aslında."
        Sunucu: "Nasıl yani?"
        Sen: "Nikâhın topluma ilan edilmesi, insanların bunu bilmesi... eğlence bunun bir parçası. Ama düğünü sadece eğlenceden ibaret görmek eksik kalıyor."
        Aradığımız davranış budur: uzun monolog üreten bir sesli bot değil, stüdyo masasındaki ikinci kişi.

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
