<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\EpisodeStatus;
use RuntimeException;

/**
 * Thrown when an Episode status change is not a permitted transition -
 * e.g. moving a Live / Completed / Archived episode back to "Ready", or
 * writing "Ready" directly instead of going through the guarded
 * MakeEpisodeReady application service.
 * (Live/Completed transitions themselves are out of scope for now.)
 */
final class InvalidEpisodeTransition extends RuntimeException
{
    public static function toReady(EpisodeStatus $from): self
    {
        return new self(sprintf(
            'Bir bölüm "%s" durumundan "Yayına hazır" durumuna geçirilemez. Yalnızca Taslak veya Hazırlanıyor durumundaki bölümler yayına hazırlanabilir.',
            $from->label(),
        ));
    }

    public static function unauthorizedReadyWrite(): self
    {
        return new self(
            'Bir bölüm doğrudan "Yayına hazır" durumuna alınamaz. '
            .'Bu geçiş yalnızca "Yayına Hazırla" işlemi (MakeEpisodeReady) '
            .'üzerinden, hazırlık kontrolü ve satır kilidi ile yapılır.',
        );
    }
}
