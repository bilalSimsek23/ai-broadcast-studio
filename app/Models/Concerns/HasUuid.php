<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a public, unguessable `uuid` string that is:
 *  - generated automatically on create when not explicitly supplied,
 *  - used for route-model binding instead of the numeric primary key.
 *
 * The numeric `id` stays the internal primary key / foreign key.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(static function (self $model): void {
            if (empty($model->{$model->getUuidColumnName()})) {
                $model->{$model->getUuidColumnName()} = (string) Str::uuid();
            }
        });
    }

    public function getUuidColumnName(): string
    {
        return 'uuid';
    }

    public function getRouteKeyName(): string
    {
        return $this->getUuidColumnName();
    }
}
