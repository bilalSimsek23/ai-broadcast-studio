<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EpisodeStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\EpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One broadcast instalment of a Show.
 *
 * @property int $id
 * @property string $uuid
 * @property int $show_id
 * @property string $title
 * @property int|null $episode_number
 * @property Carbon|null $broadcast_at
 * @property string|null $main_topic
 * @property string|null $purpose
 * @property string|null $preparation_notes
 * @property string|null $broadcast_instructions
 * @property EpisodeStatus $status
 */
#[Fillable([
    'show_id',
    'title',
    'episode_number',
    'broadcast_at',
    'main_topic',
    'purpose',
    'preparation_notes',
    'broadcast_instructions',
    'status',
])]
class Episode extends Model
{
    /** @use HasFactory<EpisodeFactory> */
    use HasFactory;

    use HasUuid;

    protected $attributes = [
        'status' => EpisodeStatus::Draft->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'broadcast_at' => 'datetime',
            'status' => EpisodeStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * @return BelongsToMany<AiPersona, $this, EpisodeAiPersona, 'pivot'>
     */
    public function aiPersonas(): BelongsToMany
    {
        return $this->belongsToMany(AiPersona::class, 'episode_ai_persona')
            ->using(EpisodeAiPersona::class)
            ->withPivot(['sort_order', 'episode_instructions'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<EpisodeTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(EpisodeTopic::class)->orderBy('sort_order');
    }
}
