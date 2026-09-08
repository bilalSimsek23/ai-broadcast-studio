<?php

declare(strict_types=1);

namespace App\AI\Realtime;

use RuntimeException;

/**
 * A live studio session was requested for an episode/persona selection that
 * cannot go on air. There is NO silent fallback for this — a wrong episode in
 * a production studio would put a generic assistant on live TV — so the
 * controller turns each case into a clear HTTP 422 for the operator.
 *
 * `reason` is a short, safe, machine-readable slug; `getMessage()` is a safe
 * Turkish operator message. Neither ever contains a credential or a raw vendor
 * error.
 */
final class StudioEpisodeUnavailable extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function episodeRequired(): self
    {
        return new self('episode_required', 'Canlı yayın için önce bir "Yayına Hazır" bölüm seçin.');
    }

    public static function episodeNotFound(): self
    {
        return new self('episode_not_found', 'Seçilen bölüm bulunamadı. Listeden geçerli bir bölüm seçin.');
    }

    public static function episodeNotReady(): self
    {
        return new self('episode_not_ready', 'Seçilen bölüm "Yayına Hazır" durumunda değil. Yalnızca hazır bölümlerle canlı yayın açılabilir.');
    }

    public static function personaRequired(): self
    {
        return new self('persona_required', 'Bu bölümün kadrosunda birden fazla AI karakteri var. Yayına çıkacak karakteri seçin.');
    }

    public static function personaNotInLineup(): self
    {
        return new self('persona_not_in_lineup', 'Seçilen AI karakteri bu bölümün kadrosunda değil.');
    }
}
