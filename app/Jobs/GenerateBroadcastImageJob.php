<?php

declare(strict_types=1);

namespace App\Jobs;

use App\AI\Imaging\GenerateBroadcastImage;
use App\Models\Episode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Generates one broadcast still image OFF the web request so a slow (high
 * quality) render is never cut short by a hosted reverse proxy's request
 * timeout (CLAUDE.md §3 — long / external-API work goes to a queued job).
 *
 * The controller writes `{status:'pending'}` under the ticket key and dispatches
 * this job; the browser polls the status endpoint. On success this job writes
 * `{status:'ready', image, mime_type, size}`; on failure `{status:'failed',
 * message}`. Nothing is persisted beyond the short-lived cache entry.
 *
 * It is NOT retried — the image API call is billed, so a failure surfaces to
 * the operator rather than silently re-charging.
 */
final class GenerateBroadcastImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** How long a result / failure stays readable by the poller. */
    private const RESULT_TTL_SECONDS = 600;

    public int $tries = 1;

    public int $timeout = 200;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $ticket,
        public readonly string $prompt,
        public readonly ?string $size,
        public readonly ?string $quality,
        public readonly ?string $episodeUuid,
    ) {}

    public function handle(GenerateBroadcastImage $generate): void
    {
        $episode = null;

        if ($this->episodeUuid !== null && $this->episodeUuid !== '') {
            $episode = Episode::query()->where('uuid', $this->episodeUuid)->with('show')->first();
        }

        $image = $generate($this->prompt, $this->size, $episode, $this->quality);

        Cache::put(
            self::cacheKey($this->ticket),
            array_merge(['status' => 'ready'], $image->toArray()),
            self::RESULT_TTL_SECONDS,
        );
    }

    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            report($e);
        }

        Cache::put(
            self::cacheKey($this->ticket),
            ['status' => 'failed', 'message' => 'Görsel oluşturulamadı. Lütfen tekrar deneyin.'],
            self::RESULT_TTL_SECONDS,
        );
    }

    public static function cacheKey(string $ticket): string
    {
        return 'studio:image:'.$ticket;
    }
}
