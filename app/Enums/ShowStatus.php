<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a Show (the recurring TV programme definition).
 */
enum ShowStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    /** Human-readable label. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    /** A show that may currently be scheduled / produced. */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
