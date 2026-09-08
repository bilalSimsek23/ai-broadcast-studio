<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;

/**
 * Turns the raw episode/persona UUIDs from the studio request into a validated
 * {@see StudioEpisodeContext}, or throws {@see StudioEpisodeUnavailable}.
 *
 * All the "is this safe to put on live TV?" business rules live HERE, not in
 * the controller:
 *  - an episode UUID must be given,
 *  - it must resolve to an existing episode,
 *  - that episode must be in `EpisodeStatus::Ready`,
 *  - the episode must have at least one line-up persona,
 *  - if the line-up has more than one persona, a persona UUID must be given,
 *  - a given persona UUID must belong to THIS episode's line-up.
 */
final readonly class ResolveStudioEpisode
{
    public function __invoke(?string $episodeUuid, ?string $personaUuid = null): StudioEpisodeContext
    {
        $episodeUuid = is_string($episodeUuid) ? trim($episodeUuid) : '';

        if ($episodeUuid === '') {
            throw StudioEpisodeUnavailable::episodeRequired();
        }

        $episode = Episode::query()
            ->where('uuid', $episodeUuid)
            ->with(['show', 'lineup.aiPersona', 'topics.questions'])
            ->first();

        if ($episode === null) {
            throw StudioEpisodeUnavailable::episodeNotFound();
        }

        if ($episode->status !== EpisodeStatus::Ready) {
            throw StudioEpisodeUnavailable::episodeNotReady();
        }

        $slots = $episode->lineup;

        if ($slots->isEmpty()) {
            throw StudioEpisodeUnavailable::personaNotInLineup();
        }

        $personaUuid = is_string($personaUuid) ? trim($personaUuid) : '';

        if ($personaUuid === '') {
            if ($slots->count() > 1) {
                throw StudioEpisodeUnavailable::personaRequired();
            }

            /** @var EpisodeAiPersona $slot */
            $slot = $slots->first();
        } else {
            $slot = $slots->first(
                static fn (EpisodeAiPersona $s): bool => $s->aiPersona->uuid === $personaUuid,
            );

            if ($slot === null) {
                throw StudioEpisodeUnavailable::personaNotInLineup();
            }
        }

        return new StudioEpisodeContext($episode, $slot->aiPersona, $slot);
    }
}
