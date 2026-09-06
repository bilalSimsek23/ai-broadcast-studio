<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\EpisodeTopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject to be debated within an episode.
 *
 * @property int $id
 * @property string $uuid
 * @property int $episode_id
 * @property string $title
 * @property string|null $description
 * @property string|null $ai_context
 * @property string|null $presenter_notes
 * @property int $sort_order
 */
#[Fillable([
    'episode_id',
    'title',
    'description',
    'ai_context',
    'presenter_notes',
    'sort_order',
])]
class EpisodeTopic extends Model
{
    /** @use HasFactory<EpisodeTopicFactory> */
    use HasFactory;

    use HasUuid;

    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Episode, $this>
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /**
     * @return HasMany<EpisodeQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EpisodeQuestion::class)->orderBy('sort_order');
    }
}
