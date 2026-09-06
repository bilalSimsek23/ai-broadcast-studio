<?php

declare(strict_types=1);

namespace App\Application\Episodes;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Start next week's episode from an existing one.
 *
 * COPIES: show, AI line-up (personas + order + per-episode instructions),
 * optionally the general broadcast instructions.
 *
 * DELIBERATELY DOES NOT COPY: status (always Draft), broadcast_at,
 * episode_number, main_topic, purpose, the presenter/AI briefs, the
 * topics/questions content, and any session/conversation history. New-week
 * editorial content is written fresh in the preparation workspace.
 */
final class DuplicateEpisode
{
    public function __invoke(Episode $source, bool $copyBroadcastInstructions = false): Episode
    {
        return DB::transaction(function () use ($source, $copyBroadcastInstructions): Episode {
            $suffix = ' (kopya)';
            // Keep the generated title within the 255-char column limit even
            // when the source title is at the limit.
            $title = Str::limit($source->title, 255 - mb_strlen($suffix), '').$suffix;

            $copy = Episode::create([
                'show_id' => $source->show_id,
                'title' => $title,
                'status' => EpisodeStatus::Draft,
                'broadcast_instructions' => $copyBroadcastInstructions ? $source->broadcast_instructions : null,
            ]);

            // Read the line-up FRESH inside the transaction - never a
            // (possibly stale) preloaded relation on the caller's instance.
            foreach ($source->lineup()->get() as $slot) {
                $copy->lineup()->create([
                    'ai_persona_id' => $slot->ai_persona_id,
                    'sort_order' => $slot->sort_order,
                    'episode_instructions' => $slot->episode_instructions,
                ]);
            }

            return $copy;
        });
    }
}
