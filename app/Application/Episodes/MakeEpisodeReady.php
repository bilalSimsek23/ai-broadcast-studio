<?php

declare(strict_types=1);

namespace App\Application\Episodes;

use App\Application\Episodes\Readiness\AssessEpisodeReadiness;
use App\Application\Episodes\Readiness\EpisodeReadiness;
use App\Enums\EpisodeStatus;
use App\Exceptions\InvalidEpisodeTransition;
use App\Models\Episode;
use App\Models\EpisodeTopic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY supported path for moving an Episode to "Ready".
 *
 * ATOMIC & concurrency-safe: it opens a transaction, re-reads the row with a
 * row lock (so a concurrent archive / edit is seen), rejects a non-preparable
 * source state, then locks the child rows the readiness assessment relies on
 * (line-up, topics, questions) so a concurrent relation-manager delete of the
 * last persona / topic / question blocks behind this transition instead of
 * committing in the gap between the check and the write. It assesses THAT
 * fresh instance and only transitions when every blocking check passes. All
 * locks are held across the check and the UPDATE. The caller's instance is
 * then synced to the persisted state.
 *
 * NOTE: on engines where `FOR UPDATE` is a no-op (SQLite - dev/test) all
 * writes are already globally serialized, so the gap does not exist there;
 * the row locks matter on Postgres/MySQL and must be verified against the
 * real engine before release (see .agents/architecture.md).
 *
 * Event-driven writes of `status = Ready` (a model save/update, or create())
 * are refused by Episode's model hooks. This service performs the transition
 * with a query-builder UPDATE, which fires no model events, and only reaches
 * that line after the locked lifecycle + readiness checks below.
 *
 * Returns the assessment. Throws InvalidEpisodeTransition when the (fresh)
 * source state is not preparable (and is not already Ready). No exception for
 * the ordinary "not ready yet" case.
 */
final class MakeEpisodeReady
{
    public function __construct(private readonly AssessEpisodeReadiness $assess) {}

    public function __invoke(Episode $episode): EpisodeReadiness
    {
        return DB::transaction(function () use ($episode): EpisodeReadiness {
            /** @var Episode $fresh */
            $fresh = Episode::query()->whereKey($episode->getKey())->lockForUpdate()->firstOrFail();

            // Lifecycle check FIRST, independent of readiness: a Live /
            // Completed / Archived episode is an invalid source for this
            // transition whether or not its editorial fields are complete.
            if ($fresh->status !== EpisodeStatus::Ready && ! $fresh->status->isPreparable()) {
                throw InvalidEpisodeTransition::toReady($fresh->status);
            }

            // Lock the readiness "witness" rows so a concurrent relation edit
            // (removing the last persona / topic / question) cannot commit
            // between the assessment below and the status write.
            $fresh->lineup()->lockForUpdate()->get(['id']);
            /** @var Collection<int, EpisodeTopic> $topics */
            $topics = $fresh->topics()->lockForUpdate()->get(['id']);
            foreach ($topics as $topic) {
                $topic->questions()->lockForUpdate()->get(['id']);
            }

            $readiness = ($this->assess)($fresh);

            if ($readiness->isReady() && $fresh->status !== EpisodeStatus::Ready) {
                // Event-free write: the one guarded path to Ready. The row is
                // held under `lockForUpdate` for the whole transaction, so
                // this cannot race a concurrent lifecycle change.
                Episode::query()
                    ->whereKey($fresh->getKey())
                    ->update(['status' => EpisodeStatus::Ready->value]);

                $fresh->refresh();
            }

            // Reflect the persisted state on the caller's instance.
            $episode->setRawAttributes($fresh->getAttributes(), sync: true);

            return $readiness;
        });
    }
}
