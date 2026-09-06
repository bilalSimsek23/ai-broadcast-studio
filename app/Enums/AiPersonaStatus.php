<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of an AiPersona (the persistent AI character definition).
 *
 * Kept separate from {@see ShowStatus} even though the cases currently match:
 * the two lifecycles are expected to diverge (e.g. a persona-specific
 * "Retired" state) and each is its own closed set.
 */
enum AiPersonaStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    /** A persona that may be assigned to an episode line-up. */
    public function isAssignable(): bool
    {
        return $this === self::Active;
    }
}
