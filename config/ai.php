<?php

declare(strict_types=1);

use App\AI\Providers\Fake\FakeTextProvider;
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
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'timeout' => (int) env('OPENAI_TEXT_TIMEOUT', 30),
                'connect_timeout' => (int) env('OPENAI_TEXT_CONNECT_TIMEOUT', 10),
            ],
        ],
    ],
];
