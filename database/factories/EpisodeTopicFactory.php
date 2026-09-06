<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Episode;
use App\Models\EpisodeTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EpisodeTopic>
 */
class EpisodeTopicFactory extends Factory
{
    protected $model = EpisodeTopic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'episode_id' => Episode::factory(),
            'title' => ucfirst(fake()->sentence(5)),
            'description' => fake()->optional()->paragraph(),
            'ai_context' => fake()->optional()->paragraph(),
            'presenter_notes' => fake()->optional()->sentence(),
            'sort_order' => 0,
        ];
    }

    public function forEpisode(Episode $episode): static
    {
        return $this->state(['episode_id' => $episode->id]);
    }
}
