<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * The vendor-neutral request to open ONE realtime voice session: the standing
 * instructions the AI voice should follow, plus an optional voice override.
 *
 * Concrete model id, endpoint and credentials are the adapter's concern
 * (resolved from config) — they never appear here.
 */
final readonly class RealtimeSessionRequest
{
    public function __construct(
        public string $instructions,
        public ?string $voiceOverride = null,
    ) {
        if (trim($instructions) === '') {
            throw new InvalidArgumentException('A realtime session needs non-empty instructions.');
        }

        if ($voiceOverride !== null && trim($voiceOverride) === '') {
            throw new InvalidArgumentException('A voice override, when provided, must not be blank.');
        }
    }
}
