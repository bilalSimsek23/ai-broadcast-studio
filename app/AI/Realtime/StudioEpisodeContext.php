<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;

/**
 * A validated, ready-to-use "this Ready episode + the persona that will speak +
 * that persona's per-episode slot" selection for a live studio session.
 *
 * Produced only by {@see ResolveStudioEpisode} (which enforces every rule) so
 * that {@see MintStudioSession} and the controller can trust it without
 * re-checking. The Episode arrives eager-loaded with `show`, `lineup.aiPersona`
 * and `topics.questions`.
 */
final readonly class StudioEpisodeContext
{
    public function __construct(
        public Episode $episode,
        public AiPersona $persona,
        public EpisodeAiPersona $slot,
    ) {}
}
