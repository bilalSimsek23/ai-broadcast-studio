<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\EpisodeQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question / discussion point under an episode topic.
 *
 * @property int $id
 * @property string $uuid
 * @property int $episode_topic_id
 * @property string $question
 * @property string|null $ai_context
 * @property string|null $presenter_notes
 * @property int $sort_order
 */
#[Fillable([
    'episode_topic_id',
    'question',
    'ai_context',
    'presenter_notes',
    'sort_order',
])]
class EpisodeQuestion extends Model
{
    /** @use HasFactory<EpisodeQuestionFactory> */
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
     * @return BelongsTo<EpisodeTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(EpisodeTopic::class, 'episode_topic_id');
    }
}
