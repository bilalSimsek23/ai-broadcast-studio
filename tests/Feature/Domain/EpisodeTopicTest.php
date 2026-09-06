<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeTopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_topic_belongs_to_the_correct_episode(): void
    {
        $episode = Episode::factory()->create();
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create();

        $topic->load('episode');
        $this->assertTrue($topic->episode->is($episode));
        $this->assertTrue($episode->load('topics')->topics->contains($topic));
    }

    public function test_topics_are_returned_in_sort_order(): void
    {
        $episode = Episode::factory()->create();
        EpisodeTopic::factory()->forEpisode($episode)->create(['title' => 'Second', 'sort_order' => 2]);
        EpisodeTopic::factory()->forEpisode($episode)->create(['title' => 'First', 'sort_order' => 1]);

        $this->assertSame(
            ['First', 'Second'],
            $episode->load('topics')->topics->pluck('title')->all(),
        );
    }

    public function test_a_topic_has_many_questions(): void
    {
        $topic = EpisodeTopic::factory()->create();
        EpisodeQuestion::factory()->forTopic($topic)->count(3)->create();

        $this->assertCount(3, $topic->load('questions')->questions);
    }

    public function test_a_topic_requires_an_existing_episode(): void
    {
        $this->expectException(QueryException::class);

        EpisodeTopic::factory()->create(['episode_id' => 999999]);
    }

    public function test_deleting_a_topic_cascades_to_its_questions(): void
    {
        $topic = EpisodeTopic::factory()->create();
        EpisodeQuestion::factory()->forTopic($topic)->count(2)->create();

        $topic->delete();

        $this->assertDatabaseCount('episode_questions', 0);
    }
}
