<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Dtos\RealtimeSessionRequest;
use App\AI\Dtos\RealtimeSessionToken;

/**
 * The vendor-neutral "open a live voice session" capability.
 *
 * An implementation takes a neutral {@see RealtimeSessionRequest} and returns a
 * neutral {@see RealtimeSessionToken} — a short-lived ephemeral secret the
 * browser uses to run the actual audio stream itself (WebRTC). The standing
 * API key stays server-side and is never part of the returned token.
 *
 * Vendor SDK classes and vendor request/response shapes live ONLY inside a
 * concrete implementation and never cross this boundary (CLAUDE.md §5).
 */
interface RealtimeVoiceProvider
{
    public function createClientSession(RealtimeSessionRequest $request): RealtimeSessionToken;
}
