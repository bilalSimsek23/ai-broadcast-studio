<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EpisodeStatus;
use App\Exceptions\InvalidEpisodeTransition;
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
 * Editorial preparation lives on THIS model and is four distinct concerns
 * (see .agents/architecture.md):
 *  - `ai_*` fields         -> the episode's AI briefing (this episode only)
 *  - `presenter`/notes     -> the human host's brief (opening/key/push/closing)
 *  - EpisodeTopic.ai_context      -> per-topic AI context
 *  - EpisodeAiPersona.episode_instructions -> per-persona, per-episode notes
 * None of these belong on the persistent AiPersona identity.
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
 * @property string|null $opening_notes
 * @property string|null $key_points
 * @property string|null $questions_to_push
 * @property string|null $closing_notes
 * @property string|null $ai_objective
 * @property string|null $ai_tone_override
 * @property string|null $must_cover_points
 * @property string|null $avoid_points
 * @property string|null $response_length_guidance
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
    'opening_notes',
    'key_points',
    'questions_to_push',
    'closing_notes',
    'ai_objective',
    'ai_tone_override',
    'must_cover_points',
    'avoid_points',
    'response_length_guidance',
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

    protected static function booted(): void
    {
        // Readiness invariant: "Ready" is a GUARDED status. The ONLY writer
        // allowed to set it is the MakeEpisodeReady application service, which
        // does so with a query-builder UPDATE inside a row-locked transaction,
        // AFTER its lifecycle + readiness checks. A builder UPDATE fires no
        // model events, so it is not seen here. Every EVENT-driven attempt to
        // persist status = Ready - a model save/update, or create() - is
        // refused, whatever the caller (Filament form, tinker, a future API,
        // application code). Fixtures that need a Ready row bypass events
        // explicitly (see EpisodeFactory::scheduled()).
        static::creating(static function (self $episode): void {
            if ($episode->status === EpisodeStatus::Ready) {
                throw InvalidEpisodeTransition::unauthorizedReadyWrite();
            }
        });

        static::updating(static function (self $episode): void {
            if ($episode->isDirty('status') && $episode->status === EpisodeStatus::Ready) {
                throw InvalidEpisodeTransition::unauthorizedReadyWrite();
            }
        });
    }

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
     * The episode line-up as first-class rows (persona slot + per-episode
     * ordering and instructions). Same underlying table as {@see aiPersonas()};
     * this hasMany is the ergonomic handle for managing the slots directly.
     *
     * @return HasMany<EpisodeAiPersona, $this>
     */
    public function lineup(): HasMany
    {
        return $this->hasMany(EpisodeAiPersona::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<EpisodeTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(EpisodeTopic::class)->orderBy('sort_order');
    }
}
