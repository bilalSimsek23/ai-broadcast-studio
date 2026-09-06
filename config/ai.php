<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI configuration (logical layer only)
|--------------------------------------------------------------------------
|
| TASK-0001 defines ONLY the allow-lists that keep vendor identifiers out of
| domain data (CLAUDE.md section 5, project-context "Vendor-neutral" hard
| constraint). Domain models persist a LOGICAL key from these lists; a later
| task adds the provider/model/voice resolution and the vendor bindings here
| (via env() in this file only).
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

];
