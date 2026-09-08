<?php

declare(strict_types=1);

use App\AI\Providers\Fake\FakeRealtimeVoiceProvider;
use App\AI\Providers\Fake\FakeTextProvider;
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

        // Hard cap (seconds) the browser enforces before it auto-disconnects a
        // session — the single source of truth for the session length. Default
        // 20 minutes; raise it (e.g. 1800–2400) from the environment for longer
        // broadcast rehearsals. The service clamps to [30, 3600].
        'session_max_seconds' => (int) env('STUDIO_LIVE_MAX_SECONDS', 1200),

        // Where the browser POSTs its WebRTC SDP offer (Bearer = ephemeral
        // secret). A vendor URL, declared here — never hardcoded in JS/PHP.
        'webrtc_url' => env('OPENAI_REALTIME_WEBRTC_URL', 'https://api.openai.com/v1/realtime/calls'),

        // The AI's standing brief for the studio voice prototype. Overridable
        // via env for tuning without a deploy.
        'instructions' => env('STUDIO_LIVE_INSTRUCTIONS', 'Sen bir canlı Türk televizyon programında, stüdyodaki insan sunucuyla Türkçe sesli olarak tartışan bir yapay zekâ konuşmacısısın. Doğal, akıcı ve kesintisiz konuş; kısa, net cümleler kur. Önce sunucuyu dinle, sonra yanıt ver. Karşıt görüşleri nazik ama kararlı biçimde savun ve gerekçelendir. Her koşulda yalnızca Türkçe konuş.'),

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

            // server_vad tuning — a MILD raise of the defaults, not a hard gate.
            // threshold: 0.5 default -> 0.6 (ignore quiet room noise);
            // prefix_padding_ms: keep 300 so the onset of speech is not lost;
            // silence_duration_ms: 200 default -> 500 (don't end a turn on a
            // brief noise). Barge-in stays on (interrupt_response).
            'turn_detection' => [
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
                'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
                'voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
                'timeout' => (int) env('OPENAI_REALTIME_TIMEOUT', 15),
                'connect_timeout' => (int) env('OPENAI_REALTIME_CONNECT_TIMEOUT', 10),
            ],
        ],
    ],
];
