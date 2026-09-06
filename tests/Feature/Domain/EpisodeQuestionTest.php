<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_belongs_to_the_correct_topic(): void
    {
        $topic = EpisodeTopic::factory()->create();
        $question = EpisodeQuestion::factory()->forTopic($topic)->create();

        $question->load('topic');
        $this->assertTrue($question->topic->is($topic));
        $this->assertSame($topic->id, $question->topic->id);
    }

    public function test_a_question_requires_an_existing_topic(): void
    {
        $this->expectException(QueryException::class);

        EpisodeQuestion::factory()->create(['episode_topic_id' => 999999]);
    }

    public function test_uuid_is_generated_and_sort_order_defaults_to_zero(): void
    {
        $question = EpisodeQuestion::create([
            'episode_topic_id' => EpisodeTopic::factory()->create()->id,
            'question' => 'Is the policy affordable?',
        ]);

        $this->assertNotEmpty($question->uuid);
        $this->assertSame(0, $question->fresh()->sort_order);
        $this->assertIsInt($question->fresh()->sort_order);
    }
}
