<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of an Episode (one broadcast instalment of a Show).
 *
 * Draft -> Preparing -> Ready -> Live -> Completed, with Archived reachable
 * from any non-live state. Transition rules are NOT enforced here yet - that
 * belongs to a later Session/lifecycle task.
 */
enum EpisodeStatus: string
{
    case Draft = 'draft';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Live = 'live';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::Live => 'Live',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /** The episode is currently on air. */
    public function isLive(): bool
    {
        return $this === self::Live;
    }

    /** The episode has aired and is now historical. */
    public function isConcluded(): bool
    {
        return $this === self::Completed || $this === self::Archived;
    }
}
