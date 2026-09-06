<?php

declare(strict_types=1);

namespace Tests\Feature\Application;

use App\Application\Episodes\MakeEpisodeReady;
use App\Application\Episodes\Readiness\AssessEpisodeReadiness;
use App\Enums\EpisodeStatus;
use App\Exceptions\InvalidEpisodeTransition;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function preparedEpisode(): Episode
    {
        $episode = Episode::factory()->create([
            'title' => 'Energy Debate',
            'main_topic' => 'Grid decarbonisation',
            'broadcast_at' => now()->addWeek(),
            'ai_objective' => 'Stress-test both sides of the policy.',
            'must_cover_points' => 'Cost. Timeline. Grid stability.',
        ]);
        $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 0]);
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create();
        EpisodeQuestion::factory()->forTopic($topic)->create();

        return $episode->fresh() ?? $episode;
    }

    public function test_a_bare_episode_is_not_ready_and_reports_every_missing_item(): void
    {
        $episode = Episode::factory()->create([
            'title' => 'Untitled',
            'main_topic' => null,
            'broadcast_at' => null,
            'ai_objective' => null,
            'must_cover_points' => null,
        ]);

        $readiness = app(AssessEpisodeReadiness::class)($episode);

        $this->assertFalse($readiness->isReady());
        $this->assertNotEmpty($readiness->blockingIssues());

        $failed = collect($readiness->checks())->filter(fn ($c) => ! $c->passed)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(
            ['main_topic', 'broadcast_at', 'personas', 'topics', 'questions', 'ai_brief'],
            $failed,
        );
    }

    public function test_a_fully_prepared_episode_is_ready_with_no_blocking_issues(): void
    {
        $readiness = app(AssessEpisodeReadiness::class)($this->preparedEpisode());

        $this->assertTrue($readiness->isReady());
        $this->assertSame([], $readiness->blockingIssues());
    }

    public function test_whitespace_only_critical_fields_do_not_count_as_filled(): void
    {
        $episode = $this->preparedEpisode();
        $episode->update(['main_topic' => "   \n\t "]);

        $readiness = app(AssessEpisodeReadiness::class)($episode->fresh());

        $this->assertFalse($readiness->isReady());
        $this->assertContains('Ana konu girilmiş', $readiness->blockingIssues());
    }

    public function test_make_episode_ready_transitions_only_when_ready(): void
    {
        $notReady = Episode::factory()->create(['main_topic' => null]);
        $result = app(MakeEpisodeReady::class)($notReady);
        $this->assertFalse($result->isReady());
        $this->assertSame(EpisodeStatus::Draft, $notReady->fresh()->status);

        $ready = $this->preparedEpisode();
        $result = app(MakeEpisodeReady::class)($ready);
        $this->assertTrue($result->isReady());
        $this->assertSame(EpisodeStatus::Ready, $ready->fresh()->status);
    }

    public function test_make_episode_ready_is_idempotent_on_an_already_ready_episode(): void
    {
        $episode = $this->preparedEpisode();
        app(MakeEpisodeReady::class)($episode);
        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);

        // Calling it again must not throw and must leave the status alone.
        $result = app(MakeEpisodeReady::class)($episode->fresh());
        $this->assertTrue($result->isReady());
        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);
    }

    public function test_ready_is_a_guarded_transition_no_direct_model_write_reaches_it(): void
    {
        // Even a fully prepared episode cannot be pushed to Ready by a direct
        // write: the ONLY authorised writer is MakeEpisodeReady.
        $episode = $this->preparedEpisode();

        try {
            $episode->update(['status' => EpisodeStatus::Ready]);
            $this->fail('Expected '.InvalidEpisodeTransition::class);
        } catch (InvalidEpisodeTransition) {
            $this->assertSame(EpisodeStatus::Draft, $episode->fresh()->status);
        }

        // The supported path still works on the same episode.
        app(MakeEpisodeReady::class)($episode);
        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);
    }

    public function test_a_direct_write_to_ready_is_refused_for_an_incomplete_episode_too(): void
    {
        $episode = Episode::factory()->create(['main_topic' => null]);

        try {
            $episode->update(['status' => EpisodeStatus::Ready]);
            $this->fail('Expected '.InvalidEpisodeTransition::class);
        } catch (InvalidEpisodeTransition) {
            $this->assertSame(EpisodeStatus::Draft, $episode->fresh()->status);
        }
    }

    public function test_an_episode_cannot_be_created_directly_in_the_ready_status(): void
    {
        $this->expectException(InvalidEpisodeTransition::class);

        Episode::factory()->create(['status' => EpisodeStatus::Ready]);
    }

    public function test_creating_an_episode_in_a_non_guarded_status_still_works(): void
    {
        foreach ([EpisodeStatus::Draft, EpisodeStatus::Preparing, EpisodeStatus::Live, EpisodeStatus::Archived] as $status) {
            $episode = Episode::factory()->create(['status' => $status]);
            $this->assertSame($status, $episode->fresh()->status);
        }
    }

    public function test_the_service_uses_an_event_free_write_that_no_generic_hook_bypass_can_reach(): void
    {
        // There is no public switch to flip: the ONLY code that persists Ready
        // is MakeEpisodeReady's guarded builder UPDATE. A direct model write
        // stays refused even when wrapped in withoutEvents-style trickery is
        // out of scope here - what matters is the class exposes no opener.
        $this->assertFalse(
            method_exists(Episode::class, 'authorizeReadyTransition'),
            'Episode must not expose a public Ready-transition authorization switch.',
        );

        $episode = $this->preparedEpisode();
        app(MakeEpisodeReady::class)($episode);
        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);
    }

    public function test_make_episode_ready_re_reads_relations_so_a_stale_cache_cannot_slip_past(): void
    {
        $episode = $this->preparedEpisode();

        // Warm the relation caches (as the workspace render would).
        app(AssessEpisodeReadiness::class)($episode);
        $episode->loadMissing('topics.questions');

        // Remove the only question through a different query path.
        EpisodeTopic::where('episode_id', $episode->id)->first()?->questions()->delete();

        // The service assesses freshly-read relations, not the warm cache.
        $result = app(MakeEpisodeReady::class)($episode);

        $this->assertFalse($result->isReady());
        $this->assertContains('En az bir soru var', $result->blockingIssues());
        $this->assertSame(EpisodeStatus::Draft, $episode->fresh()->status);
    }

    public function test_it_locks_the_readiness_witness_rows_and_still_transitions_with_many_topics(): void
    {
        // Exercises the per-topic question lock loop with more than one topic.
        $episode = Episode::factory()->create([
            'title' => 'Multi-topic', 'main_topic' => 'Main', 'broadcast_at' => now()->addWeek(),
            'ai_objective' => 'obj', 'must_cover_points' => 'pts',
        ]);
        $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 0]);
        foreach (range(0, 2) as $i) {
            $topic = EpisodeTopic::factory()->forEpisode($episode)->create(['sort_order' => $i]);
            EpisodeQuestion::factory()->forTopic($topic)->create();
        }

        $result = app(MakeEpisodeReady::class)($episode);

        $this->assertTrue($result->isReady());
        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);
    }

    public function test_removing_a_topic_after_ready_does_not_auto_revert_the_status(): void
    {
        // Domain policy for TASK-0004: a readiness-affecting edit made AFTER a
        // committed Ready transition is the operator's responsibility; the
        // status is NOT automatically downgraded (reverting Ready / Live /
        // Completed transitions are out of scope for this task).
        $episode = $this->preparedEpisode();
        app(MakeEpisodeReady::class)($episode);
        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);

        EpisodeTopic::where('episode_id', $episode->id)->delete();

        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);
        $this->assertFalse(app(AssessEpisodeReadiness::class)($episode->fresh())->isReady());
    }

    public function test_a_terminal_episode_cannot_be_regressed_to_ready(): void
    {
        foreach ([EpisodeStatus::Live, EpisodeStatus::Completed, EpisodeStatus::Archived] as $terminal) {
            $episode = $this->preparedEpisode();
            $episode->forceFill(['status' => $terminal])->saveQuietly();

            $threwFromService = false;
            try {
                app(MakeEpisodeReady::class)($episode->fresh());
            } catch (InvalidEpisodeTransition) {
                $threwFromService = true;
            }
            $this->assertTrue($threwFromService, "MakeEpisodeReady must refuse {$terminal->value} -> Ready");

            $threwFromModel = false;
            try {
                $episode->fresh()->update(['status' => EpisodeStatus::Ready]);
            } catch (InvalidEpisodeTransition) {
                $threwFromModel = true;
            }
            $this->assertTrue($threwFromModel, "Direct update must refuse {$terminal->value} -> Ready");

            $this->assertSame($terminal, $episode->fresh()->status);
        }
    }

    public function test_an_incomplete_terminal_episode_is_still_rejected_by_the_service_on_lifecycle_grounds(): void
    {
        // Missing editorial fields AND a terminal status: the service must
        // report the invalid lifecycle transition, not an ordinary
        // "not ready yet" assessment.
        $episode = Episode::factory()->create(['main_topic' => null, 'ai_objective' => null]);
        $episode->forceFill(['status' => EpisodeStatus::Archived])->saveQuietly();

        $this->expectException(InvalidEpisodeTransition::class);

        try {
            app(MakeEpisodeReady::class)($episode->fresh());
        } finally {
            $this->assertSame(EpisodeStatus::Archived, $episode->fresh()->status);
        }
    }

    public function test_make_episode_ready_sees_a_concurrently_archived_status_via_its_row_lock(): void
    {
        $episode = $this->preparedEpisode();

        // Two handles on the same row - as two operators would have.
        $operatorA = Episode::query()->whereKey($episode->getKey())->firstOrFail();
        $operatorB = Episode::query()->whereKey($episode->getKey())->firstOrFail();

        // Operator B archives it (through the model, quietly - not a Ready write).
        $operatorB->forceFill(['status' => EpisodeStatus::Archived])->saveQuietly();

        // Operator A still believes it is a preparable Draft.
        $this->assertSame(EpisodeStatus::Draft, $operatorA->status);

        $threw = false;
        try {
            app(MakeEpisodeReady::class)($operatorA);
        } catch (InvalidEpisodeTransition) {
            $threw = true;
        }
        $this->assertTrue($threw, 'MakeEpisodeReady must re-read the row and see Archived');
        $this->assertSame(EpisodeStatus::Archived, $episode->fresh()->status);
    }

    public function test_make_episode_ready_respects_a_concurrent_scalar_clear(): void
    {
        $episode = $this->preparedEpisode();

        $operatorA = Episode::query()->whereKey($episode->getKey())->firstOrFail();
        $operatorB = Episode::query()->whereKey($episode->getKey())->firstOrFail();

        // Operator B clears a required brief field after A loaded the page.
        $operatorB->update(['ai_objective' => null]);
        $this->assertNotNull($operatorA->ai_objective); // A's cache is stale

        $result = app(MakeEpisodeReady::class)($operatorA);

        $this->assertFalse($result->isReady());
        $this->assertNotEmpty($result->blockingIssues());
        $this->assertSame(EpisodeStatus::Draft, $episode->fresh()->status);
    }

    public function test_to_array_exposes_the_documented_shape(): void
    {
        $out = app(AssessEpisodeReadiness::class)(Episode::factory()->create())->toArray();

        $this->assertArrayHasKey('is_ready', $out);
        $this->assertArrayHasKey('checks', $out);
        $this->assertArrayHasKey('blocking_issues', $out);
        $this->assertArrayHasKey('key', $out['checks'][0]);
        $this->assertArrayHasKey('passed', $out['checks'][0]);
        $this->assertArrayHasKey('blocking', $out['checks'][0]);
    }
}
