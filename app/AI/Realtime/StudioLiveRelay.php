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

    private const OWNER_KEY = 'studio:live:owner';

    /** Ownership lease; refreshed by the engine's heartbeat every ~5 s. */
    private const OWNER_TTL = 20;

    /** Owner record older than this (seconds) is treated as no owner. */
    private const OWNER_FRESH_SECONDS = 12;

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
     * The current audio-engine owner, or null when none is fresh.
     *
     * @return array{engineId: string, at: int, fresh: bool}|null
     */
    public function owner(): ?array
    {
        $o = Cache::get(self::OWNER_KEY);

        if (! is_array($o) || ! is_string($o['engineId'] ?? null) || $o['engineId'] === '') {
            return null;
        }

        $at = is_int($o['at'] ?? null) ? $o['at'] : 0;

        return ['engineId' => $o['engineId'], 'at' => $at, 'fresh' => (time() - $at) <= self::OWNER_FRESH_SECONDS];
    }

    /**
     * Claim (or refresh) the single audio-engine ownership. `force` takes it
     * over from a live owner; otherwise it is granted only when the slot is
     * free / stale / already this engine's.
     *
     * @return array{granted: bool, engineId?: string, owner?: array{engineId: string, at: int, fresh: bool}}
     */
    public function claimOwner(string $engineId, bool $force): array
    {
        $record = ['engineId' => $engineId, 'at' => time()];

        if ($force) {
            Cache::put(self::OWNER_KEY, $record, self::OWNER_TTL);

            return ['granted' => true, 'engineId' => $engineId];
        }

        if (Cache::add(self::OWNER_KEY, $record, self::OWNER_TTL)) {
            return ['granted' => true, 'engineId' => $engineId];
        }

        $current = $this->owner();

        if ($current === null || ! $current['fresh'] || $current['engineId'] === $engineId) {
            Cache::put(self::OWNER_KEY, $record, self::OWNER_TTL);

            return ['granted' => true, 'engineId' => $engineId];
        }

        return ['granted' => false, 'owner' => $current];
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
