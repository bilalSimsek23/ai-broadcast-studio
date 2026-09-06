<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiPersonaStatus;
use App\Exceptions\InvalidLogicalConfigKey;
use App\Models\Concerns\HasUuid;
use Database\Factories\AiPersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A persistent AI character that can be cast into episodes.
 *
 * Its persistent identity lives here. Anything episode-specific (topic,
 * per-episode instructions, ordering) lives on the episode_ai_persona pivot,
 * never on this table.
 *
 * The `ai_*` / `voice_*` columns hold LOGICAL configuration keys (resolved via
 * config/ai.php in a later task), never a vendor name, base URL, or raw model
 * id - see CLAUDE.md section 5 and .agents/architecture.md section 2a.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $title
 * @property string|null $biography
 * @property string|null $expertise
 * @property string|null $personality
 * @property string|null $speaking_style
 * @property string|null $system_prompt
 * @property string|null $ai_provider logical text-generation profile key
 * @property string|null $ai_model logical model tier key (optional)
 * @property string|null $voice_provider logical voice profile key
 * @property string|null $voice_id logical voice key within the profile
 * @property array<string, mixed>|null $screen_settings
 * @property AiPersonaStatus $status
 */
#[Fillable([
    'name',
    'title',
    'biography',
    'expertise',
    'personality',
    'speaking_style',
    'system_prompt',
    'ai_provider',
    'ai_model',
    'voice_provider',
    'voice_id',
    'screen_settings',
    'status',
])]
class AiPersona extends Model
{
    /** @use HasFactory<AiPersonaFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * Binding column => config/ai.php allow-list path. Every non-null value in
     * these columns must be a registered LOGICAL key, never a vendor name or
     * raw model id (CLAUDE.md section 5).
     *
     * @var array<string, string>
     */
    private const LOGICAL_KEY_COLUMNS = [
        'ai_provider' => 'ai.persona.ai_provider',
        'ai_model' => 'ai.persona.ai_model',
        'voice_provider' => 'ai.persona.voice_provider',
        'voice_id' => 'ai.persona.voice_id',
    ];

    protected $attributes = [
        'status' => AiPersonaStatus::Draft->value,
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $persona): void {
            $persona->assertLogicalConfigKeys();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'screen_settings' => 'array',
            'status' => AiPersonaStatus::class,
        ];
    }

    /**
     * Reject any provider/voice value that is not a registered logical key.
     *
     * @throws InvalidLogicalConfigKey
     */
    public function assertLogicalConfigKeys(): void
    {
        foreach (self::LOGICAL_KEY_COLUMNS as $column => $configPath) {
            $value = $this->getAttribute($column);

            // Only NULL bypasses the allow-list. An empty string is a
            // non-null, unregistered key and is rejected like any other.
            if ($value === null) {
                continue;
            }

            /** @var array<int, string> $allowed */
            $allowed = (array) config($configPath, []);
            if (! in_array($value, $allowed, true)) {
                throw InvalidLogicalConfigKey::for(self::class, $column, $configPath);
            }
        }
    }

    /**
     * @return BelongsToMany<Episode, $this>
     */
    public function episodes(): BelongsToMany
    {
        return $this->belongsToMany(Episode::class, 'episode_ai_persona')
            ->using(EpisodeAiPersona::class)
            ->withPivot(['sort_order', 'episode_instructions'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
