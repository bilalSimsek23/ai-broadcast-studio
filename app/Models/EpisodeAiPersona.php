<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EpisodeAiPersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The episode_ai_persona pivot row: one AI character's slot in one episode.
 *
 * @property int $episode_id
 * @property int $ai_persona_id
 * @property int $sort_order
 * @property string|null $episode_instructions
 */
#[Fillable(['episode_id', 'ai_persona_id', 'sort_order', 'episode_instructions'])]
class EpisodeAiPersona extends Pivot
{
    /** @use HasFactory<EpisodeAiPersonaFactory> */
    use HasFactory;

    protected $table = 'episode_ai_persona';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
