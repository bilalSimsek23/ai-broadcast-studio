<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShowStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\ShowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring television programme.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ShowStatus $status
 */
#[Fillable(['name', 'slug', 'description', 'status'])]
class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

    use HasUuid;

    protected $attributes = [
        'status' => ShowStatus::Draft->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShowStatus::class,
        ];
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }
}
