<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use Illuminate\Support\Facades\Cache;

/**
 * A tiny server-side relay for the ONE studio: the operator console writes a
 * level-based "control document" (what the broadcast screen should be doing);
 * the broadcast screen polls it and reconciles, and posts back a "state
 * document" the console polls for its readouts.
 *
 * This carries COMMANDS + STATE only — never audio. The WebRTC media still runs
 * browser <-> OpenAI directly. It exists so the broadcast screen can run
 * remotely / in a separate browser (vMix, OBS, another machine) where a
 * same-origin BroadcastChannel cannot reach it.
 *
 * Backed by the cache (database / redis in production). Single studio => a
 * single well-known key per document; no per-session ids.
 */
final class StudioLiveRelay
{
    private const CONTROL_KEY = 'studio:live:control';

    private const STATE_KEY = 'studio:live:state';

    /** Control persists so a reconnecting screen picks up the current intent. */
    private const CONTROL_TTL = 86_400;

    /** State expires quickly so a dead screen stops looking "alive". */
    private const STATE_TTL = 30;

    /** A state older than this (seconds) is treated as no screen present. */
    private const STATE_FRESH_SECONDS = 6;

    /**
     * @return array<string, mixed>
     */
    public function control(): array
    {
        $doc = Cache::get(self::CONTROL_KEY);

        if (! is_array($doc)) {
            return self::defaultControl();
        }

        return array_merge(self::defaultControl(), $doc);
    }

    /**
     * Merge the operator's changes into the control document, bump the
     * revision, and return the new document.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function putControl(array $changes): array
    {
        $current = $this->control();

        $rev = is_int($current['rev'] ?? null) ? $current['rev'] : 0;

        $next = array_merge($current, array_filter(
            $changes,
            static fn (mixed $value): bool => $value !== null,
        ));

        $next['rev'] = $rev + 1;
        $next['updatedAt'] = time();

        Cache::put(self::CONTROL_KEY, $next, self::CONTROL_TTL);

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $doc = Cache::get(self::STATE_KEY);

        if (! is_array($doc)) {
            return ['alive' => false];
        }

        $updatedAt = is_int($doc['updatedAt'] ?? null) ? $doc['updatedAt'] : 0;
        $doc['alive'] = (time() - $updatedAt) <= self::STATE_FRESH_SECONDS;

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function putState(array $state): void
    {
        $state['updatedAt'] = time();

        Cache::put(self::STATE_KEY, $state, self::STATE_TTL);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultControl(): array
    {
        return [
            'desired' => 'idle',
            'muted' => false,
            'voiceId' => null,
            'inputId' => null,
            'outputId' => null,
            'episodeUuid' => null,
            'personaUuid' => null,
            'durationSeconds' => null,
            'image' => ['visible' => false, 'ticket' => null],
            'rev' => 0,
            'updatedAt' => 0,
        ];
    }
}
