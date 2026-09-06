<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EpisodeQuestion>
 */
class EpisodeQuestionFactory extends Factory
{
    protected $model = EpisodeQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'episode_topic_id' => EpisodeTopic::factory(),
            'question' => rtrim(ucfirst(fake()->sentence(8)), '.').'?',
            'ai_context' => fake()->optional()->paragraph(),
            'presenter_notes' => fake()->optional()->sentence(),
            'sort_order' => 0,
        ];
    }

    public function forTopic(EpisodeTopic $topic): static
    {
        return $this->state(['episode_topic_id' => $topic->id]);
    }
}
