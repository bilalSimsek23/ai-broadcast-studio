<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use App\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EpisodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_episode_belongs_to_a_show(): void
    {
        $show = Show::factory()->create();
        $episode = Episode::factory()->forShow($show)->create();

        $episode->load('show');
        $this->assertTrue($episode->show->is($show));
    }

    public function test_episode_number_and_broadcast_at_are_cast(): void
    {
        $episode = Episode::factory()->create([
            'episode_number' => '12',
            'broadcast_at' => '2026-10-01 20:00:00',
        ]);

        $fresh = $episode->fresh();
        $this->assertSame(12, $fresh->episode_number);
        $this->assertInstanceOf(Carbon::class, $fresh->broadcast_at);
        $this->assertSame('2026-10-01 20:00:00', $fresh->broadcast_at->toDateTimeString());
    }

    public function test_broadcast_at_can_hold_a_date_far_beyond_the_timestamp_epoch_limit(): void
    {
        // The column is DATETIME (not MySQL TIMESTAMP) so forward scheduling
        // past 2038 must round-trip cleanly.
        $future = '2099-12-31 20:00:00';
        $episode = Episode::factory()->create(['broadcast_at' => $future]);

        $this->assertSame($future, $episode->fresh()->broadcast_at->toDateTimeString());
    }

    public function test_status_is_cast_to_the_enum(): void
    {
        $episode = Episode::factory()->live()->create();

        $this->assertInstanceOf(EpisodeStatus::class, $episode->status);
        $this->assertSame(EpisodeStatus::Live, $episode->fresh()->status);
        $this->assertTrue($episode->fresh()->status->isLive());
    }

    public function test_creating_an_episode_with_a_missing_show_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        Episode::factory()->create(['show_id' => 999999]);
    }

    public function test_a_show_with_episodes_cannot_be_deleted(): void
    {
        $show = Show::factory()->create();
        Episode::factory()->forShow($show)->create();

        $this->expectException(QueryException::class);
        $show->delete();
    }

    public function test_deleting_an_episode_cascades_to_its_topics_and_questions(): void
    {
        $episode = Episode::factory()->create();
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create();
        EpisodeQuestion::factory()->forTopic($topic)->count(2)->create();

        $showId = $episode->show_id;
        $episode->delete();

        $this->assertDatabaseCount('episode_topics', 0);
        $this->assertDatabaseCount('episode_questions', 0);
        // The parent Show is untouched.
        $this->assertDatabaseHas('shows', ['id' => $showId]);
    }
}
