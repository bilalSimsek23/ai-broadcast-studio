<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiPersonaStatus;
use App\Models\AiPersona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPersona>
 */
class AiPersonaFactory extends Factory
{
    protected $model = AiPersona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'title' => fake()->optional()->jobTitle(),
            'biography' => fake()->optional()->paragraph(),
            'expertise' => fake()->optional()->sentence(),
            'personality' => fake()->optional()->sentence(),
            'speaking_style' => fake()->optional()->words(3, true),
            'system_prompt' => fake()->optional()->paragraph(),
            // Logical config keys only (never vendor names / raw model ids).
            'ai_provider' => fake()->optional()->randomElement(['default', 'fast', 'host_rebuttal']),
            'ai_model' => null,
            'voice_provider' => fake()->optional()->randomElement(['default', 'narration']),
            'voice_id' => null,
            'screen_settings' => [
                'position' => fake()->randomElement(['left', 'right', 'center']),
                'accent_color' => fake()->hexColor(),
            ],
            'status' => AiPersonaStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => AiPersonaStatus::Active]);
    }

    public function archived(): static
    {
        return $this->state(['status' => AiPersonaStatus::Archived]);
    }

    public function withoutScreenSettings(): static
    {
        return $this->state(['screen_settings' => null]);
    }
}
