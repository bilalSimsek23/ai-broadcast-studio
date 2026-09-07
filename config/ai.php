<?php

declare(strict_types=1);
use App\AI\Providers\Fake\FakeTextProvider;

/*
|--------------------------------------------------------------------------
| AI configuration (logical layer only)
|--------------------------------------------------------------------------
|
| Domain models and application/domain code reference LOGICAL keys only; a
| vendor name, base URL or raw model id never appears outside this file and
| the future app/AI/Providers/<Vendor>/ adapters (CLAUDE.md §5).
|
|  - `persona`  (TASK-0001): allow-lists that keep vendor identifiers out of
|    the AiPersona binding columns.
|  - `text`     (TASK-0005): the logical -> provider/model/params resolution
|    for text generation. Only the deterministic fake driver exists today;
|    real vendor adapters (with credentials read via env() IN THIS FILE ONLY)
|    are added in a later task by appending to `text.drivers` / a
|    `text.connections` entry.
|
*/

return [

    // Allowed logical keys for the AiPersona provider/voice binding columns.
    // Extend these lists as new profiles are defined - never add a vendor name,
    // base URL, or raw model id.
    'persona' => [
        'ai_provider' => ['default', 'fast', 'host_rebuttal'],
        'ai_model' => ['default', 'small', 'large'],
        'voice_provider' => ['default', 'narration'],
        'voice_id' => ['default', 'host', 'guest', 'analyst'],
    ],

    'text' => [

        /*
        | Logical PROVIDER key -> a concrete driver. Application/domain code
        | names a logical key only (e.g. 'default'); it never names a vendor.
        | These keys are a superset of config('ai.persona.ai_provider'), so
        | every value an AiPersona may store resolves here.
        */
        'providers' => [
            'default' => ['driver' => 'fake'],
            'fast' => ['driver' => 'fake'],
            'host_rebuttal' => ['driver' => 'fake'],
        ],

        /*
        | Logical MODEL key -> provider key + vendor model identifier + default
        | generation parameters. The vendor model id lives ONLY here. Keys
        | 'default', 'small', 'large' match config('ai.persona.ai_model');
        | 'host_rebuttal' is available for application-level calls.
        */
        'models' => [
            'default' => [
                'provider' => 'default',
                'model' => 'fake-balanced-v1',
                'parameters' => ['temperature' => 0.7, 'max_output_tokens' => 800],
            ],
            'small' => [
                'provider' => 'fast',
                'model' => 'fake-small-v1',
                'parameters' => ['temperature' => 0.4, 'max_output_tokens' => 400],
            ],
            'large' => [
                'provider' => 'default',
                'model' => 'fake-large-v1',
                'parameters' => ['temperature' => 0.7, 'max_output_tokens' => 2000],
            ],
            'host_rebuttal' => [
                'provider' => 'host_rebuttal',
                'model' => 'fake-rebuttal-v1',
                'parameters' => ['temperature' => 0.9, 'max_output_tokens' => 600],
            ],
        ],

        /*
        | Driver key -> class implementing
        | App\AI\Contracts\TextGenerationProvider. Only the deterministic fake
        | exists today; a real adapter task simply adds entries here.
        */
        'drivers' => [
            'fake' => FakeTextProvider::class,
        ],

        /*
        | Per-driver connection settings (base URL, credentials via env() HERE
        | ONLY). Empty until a real adapter is added, e.g.:
        |   'openai' => ['api_key' => env('OPENAI_API_KEY'), 'base_url' => env('OPENAI_BASE_URL')],
        */
        'connections' => [
            'fake' => [],
        ],
    ],

];
