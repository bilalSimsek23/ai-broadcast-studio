<?php

declare(strict_types=1);

use App\AI\Providers\Fake\FakeImageProvider;
use App\AI\Providers\Fake\FakeRealtimeVoiceProvider;
use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Providers\OpenAi\OpenAiImageProvider;
use App\AI\Providers\OpenAi\OpenAiRealtimeProvider;
use App\AI\Providers\OpenAi\OpenAiTextProvider;

return [
    'persona' => [
        'ai_provider' => ['default', 'fast', 'host_rebuttal'],
        'ai_model' => ['default', 'small', 'large'],
        'voice_provider' => ['default', 'narration'],
        'voice_id' => ['default', 'host', 'guest', 'analyst'],
    ],

    'text' => [
        /*
        |----------------------------------------------------------------------
        | Logical providers
        |----------------------------------------------------------------------
        | Product/domain code and AiPersona rows only ever name a LOGICAL
        | provider key. Which concrete driver backs it is an environment
        | decision: unset (local / CI / tests) keeps the deterministic,
        | network-free `fake` driver; `AI_TEXT_DRIVER=openai` in production
        | routes the same logical keys through the real OpenAI adapter. No
        | vendor name ever appears on a persona.
        */
        'providers' => [
            'default' => ['driver' => env('AI_TEXT_DRIVER', 'fake')],
            'fast' => ['driver' => env('AI_TEXT_DRIVER', 'fake')],
            'host_rebuttal' => ['driver' => env('AI_TEXT_DRIVER', 'fake')],
        ],

        /*
        |----------------------------------------------------------------------
        | Logical models
        |----------------------------------------------------------------------
        | Each logical model key maps to a logical provider, the concrete
        | vendor model id to call it with, and centrally-validated default
        | parameters. The vendor model id is env-overridable so production can
        | point `default` at e.g. `gpt-4o-mini` without a code change; the
        | fallback is the fake vendor id used everywhere the fake driver runs.
        */
        'models' => [
            'default' => [
                'provider' => 'default',
                'model' => env('AI_TEXT_MODEL_DEFAULT', 'fake-balanced-v1'),
                'parameters' => ['temperature' => 0.7, 'max_output_tokens' => 800],
            ],
            'small' => [
                'provider' => 'fast',
                'model' => env('AI_TEXT_MODEL_SMALL', 'fake-small-v1'),
                'parameters' => ['temperature' => 0.4, 'max_output_tokens' => 400],
            ],
            'large' => [
                'provider' => 'default',
                'model' => env('AI_TEXT_MODEL_LARGE', 'fake-large-v1'),
                'parameters' => ['temperature' => 0.7, 'max_output_tokens' => 2000],
            ],
            'host_rebuttal' => [
                'provider' => 'host_rebuttal',
                'model' => env('AI_TEXT_MODEL_HOST_REBUTTAL', 'fake-rebuttal-v1'),
                'parameters' => ['temperature' => 0.9, 'max_output_tokens' => 600],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Drivers
        |----------------------------------------------------------------------
        | driver key => the App\AI\Contracts\TextGenerationProvider class that
        | implements it. The container resolves the class, so a driver that
        | needs credentials (openai) is wired up in App\AI\AiServiceProvider
        | from its `connections` entry below.
        */
        'drivers' => [
            'fake' => FakeTextProvider::class,
            'openai' => OpenAiTextProvider::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Per-driver connections
        |----------------------------------------------------------------------
        | Credentials and transport settings, read from the environment HERE
        | only (never in application code, never persisted). The api key is
        | never logged, echoed, or surfaced in an exception.
        */
        'connections' => [
            'fake' => [],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                // Responses API base; the adapter appends `/responses`.
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'timeout' => (int) env('OPENAI_TEXT_TIMEOUT', 30),
                'connect_timeout' => (int) env('OPENAI_TEXT_CONNECT_TIMEOUT', 10),
                // GPT-5.x reasoning models reject any non-default `temperature`
                // (HTTP 400 unsupported_value). Keep this false for that family;
                // set OPENAI_TEXT_SEND_SAMPLING=true only for a GPT-4-class
                // deployment that should honour the neutral `temperature`.
                'send_sampling_params' => (bool) env('OPENAI_TEXT_SEND_SAMPLING', false),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime voice (studio live prototype — TASK-0007)
    |--------------------------------------------------------------------------
    | A separate capability from `text`: a live browser<->AI Turkish voice
    | conversation for the /studio/live prototype. Transport is WebRTC; the
    | Laravel backend only mints a SHORT-LIVED ephemeral client secret from the
    | standing OPENAI_API_KEY (which never reaches the browser).
    |
    | `driver`: unset / "fake" = an offline stub (local / CI / tests); "openai"
    | = the real OpenAI Realtime API.
    */
    'realtime' => [
        'driver' => env('AI_REALTIME_DRIVER', 'fake'),

        // Default session length (seconds) the browser enforces before it
        // auto-disconnects. 20 minutes by default; raise it from the
        // environment for longer broadcasts. `0` = NO limit. The default path
        // clamps a non-zero value to [30, 3600]; an operator pick from
        // `session_durations` below is trusted as-is.
        'session_max_seconds' => (int) env('STUDIO_LIVE_MAX_SECONDS', 1200),

        // The lengths the director may pick from on the Studio Control page
        // (key = seconds, `0` = no limit). The request is validated against
        // these KEYS server-side; an unlisted value falls back to
        // `session_max_seconds`.
        'session_durations' => [
            1200 => '20 dakika',
            2400 => '40 dakika',
            3600 => '60 dakika',
            7200 => '2 saat',
            0 => 'Sınırsız',
        ],

        // Where the browser POSTs its WebRTC SDP offer (Bearer = ephemeral
        // secret). A vendor URL, declared here — never hardcoded in JS/PHP.
        'webrtc_url' => env('OPENAI_REALTIME_WEBRTC_URL', 'https://api.openai.com/v1/realtime/calls'),

        // FALLBACK brief for a STANDALONE (no episode) session. The normal
        // production flow binds a Ready Episode and builds the instructions
        // from its prepared content (App\AI\Prompting\AssembleEpisodeBriefing).
        // This string is only used when a session is opened with no episode.
        'instructions' => env('STUDIO_LIVE_INSTRUCTIONS', 'Sen bir canlı Türk televizyon programında, stüdyodaki insan sunucuyla Türkçe sesli olarak tartışan bir yapay zekâ konuşmacısısın. Doğal, akıcı ve kesintisiz konuş; kısa, net cümleler kur. Önce sunucuyu dinle, sonra yanıt ver. Karşıt görüşleri nazik ama kararlı biçimde savun ve gerekçelendir. Her koşulda yalnızca Türkçe konuş.'),

        // Whether POST /studio/live/session may open a session with NO episode
        // (the standalone fallback above). OFF by default: a normal admin
        // studio session must select a Ready episode. Turn on only for local
        // development / an explicit diagnostic.
        'allow_standalone_session' => (bool) env('STUDIO_LIVE_ALLOW_STANDALONE', false),

        // The voices the director may pick from on the Studio Control page. The
        // request is validated against these KEYS server-side before it is sent
        // to the vendor. `marin` and `cedar` (the gpt-realtime GA pair) are the
        // most natural — listed first. Default is `marin`
        // (`connections.openai.voice`); an unlisted / unset choice falls back
        // to that default.
        'voices' => [
            'marin' => 'Marin — kadın (doğal)',
            'cedar' => 'Cedar — erkek (doğal)',
            'ash' => 'Ash — erkek',
            'ballad' => 'Ballad — erkek',
            'verse' => 'Verse — erkek',
            'coral' => 'Coral — kadın',
            'sage' => 'Sage — kadın',
            'alloy' => 'Alloy — nötr',
        ],

        // Studio-room audio handling. All values are config, not literals in
        // the frontend: `constraints` are passed through to the browser's
        // getUserMedia; the rest tunes the OpenAI Realtime input pipeline so
        // ambient noise (fan/AC/distant talk) is less likely to be taken as
        // speech WITHOUT clipping the start of a real utterance or breaking
        // barge-in.
        'audio' => [
            'constraints' => [
                'echoCancellation' => (bool) env('STUDIO_LIVE_ECHO_CANCELLATION', true),
                'noiseSuppression' => (bool) env('STUDIO_LIVE_NOISE_SUPPRESSION', true),
                'autoGainControl' => (bool) env('STUDIO_LIVE_AUTO_GAIN', true),
            ],

            // OpenAI input noise reduction profile: near_field | far_field | off.
            // "far_field" suits a studio mic that is not right at the mouth.
            'noise_reduction' => env('STUDIO_LIVE_NOISE_REDUCTION', 'far_field'),

            // Turn-detection mode. `semantic_vad` lets the model decide when the
            // speaker is done (a more natural, human conversational feel);
            // `eagerness` is how quickly it takes the floor. Revert to the
            // threshold-based detector with STUDIO_LIVE_VAD_TYPE=server_vad, in
            // which case the threshold / padding / silence values below apply (a
            // MILD raise of the defaults, not a hard gate). Barge-in stays on
            // (interrupt_response) either way.
            'turn_detection' => [
                'type' => env('STUDIO_LIVE_VAD_TYPE', 'semantic_vad'),
                'eagerness' => env('STUDIO_LIVE_VAD_EAGERNESS', 'auto'),
                'threshold' => (float) env('STUDIO_LIVE_VAD_THRESHOLD', 0.6),
                'prefix_padding_ms' => (int) env('STUDIO_LIVE_VAD_PREFIX_MS', 300),
                'silence_duration_ms' => (int) env('STUDIO_LIVE_VAD_SILENCE_MS', 500),
            ],
        ],

        'drivers' => [
            'fake' => FakeRealtimeVoiceProvider::class,
            'openai' => OpenAiRealtimeProvider::class,
        ],

        'connections' => [
            'fake' => [],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                // The adapter appends `/realtime/client_secrets`.
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                // Env-overridable so a newer snapshot (e.g. gpt-realtime-2) can
                // be selected without a code change; the code default stays the
                // known-good GA id.
                'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
                // Default voice: `marin` (natural, female). `cedar` (natural,
                // male) is the alternative — the director switches per session
                // from Studio Control.
                'voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
                'timeout' => (int) env('OPENAI_REALTIME_TIMEOUT', 15),
                'connect_timeout' => (int) env('OPENAI_REALTIME_CONNECT_TIMEOUT', 10),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcast still image (operator-generated)
    |--------------------------------------------------------------------------
    | A separate capability again: the operator types a prompt on the Studio
    | Control page, the backend generates ONE image and returns the bytes
    | base64-encoded, and the operator pushes it to the broadcast screen. The
    | image is never stored — it lives only in the browser and travels over a
    | same-origin BroadcastChannel.
    |
    | `driver`: unset / "fake" = an offline 1x1-PNG stub (local / CI / tests);
    | "openai" = the real OpenAI image API (`POST {base}/images/generations`).
    */
    'image' => [
        'driver' => env('AI_IMAGE_DRIVER', 'fake'),

        // Default pixel size. The operator may pick another from `sizes`; the
        // request is validated against these KEYS server-side.
        'size' => env('AI_IMAGE_SIZE', '1536x1024'),
        'sizes' => [
            '1536x1024' => 'Yatay — 1536×1024 (yayın ekranı)',
            '1024x1024' => 'Kare — 1024×1024',
            '1024x1536' => 'Dikey — 1024×1536',
        ],

        'drivers' => [
            'fake' => FakeImageProvider::class,
            'openai' => OpenAiImageProvider::class,
        ],

        'connections' => [
            'fake' => [],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                // The adapter appends `/images/generations`.
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
                // low | medium | high | auto. "auto" is the model default and is
                // not sent on the wire.
                'quality' => env('OPENAI_IMAGE_QUALITY', 'auto'),
                // Generous — image generation is synchronous and slow. The
                // calling endpoint is rate-limited (throttle:6,1).
                'timeout' => (int) env('OPENAI_IMAGE_TIMEOUT', 60),
                'connect_timeout' => (int) env('OPENAI_IMAGE_CONNECT_TIMEOUT', 10),
            ],
        ],
    ],
];
