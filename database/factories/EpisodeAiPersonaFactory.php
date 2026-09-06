<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EpisodeAiPersona>
 */
class EpisodeAiPersonaFactory extends Factory
{
    protected $model = EpisodeAiPersona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'episode_id' => Episode::factory(),
            'ai_persona_id' => AiPersona::factory(),
            'sort_order' => 0,
            'episode_instructions' => fake()->optional()->sentence(),
        ];
    }

    public function forEpisode(Episode $episode): static
    {
        return $this->state(['episode_id' => $episode->id]);
    }

    public function forPersona(AiPersona $persona): static
    {
        return $this->state(['ai_persona_id' => $persona->id]);
    }

    public function position(int $sortOrder): static
    {
        return $this->state(['sort_order' => $sortOrder]);
    }
}
