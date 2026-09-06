<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Episode>
 */
class EpisodeFactory extends Factory
{
    protected $model = Episode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => Show::factory(),
            'title' => ucfirst(fake()->sentence(4)),
            'episode_number' => fake()->optional()->numberBetween(1, 250),
            'broadcast_at' => fake()->optional()->dateTimeBetween('-1 month', '+1 month'),
            'main_topic' => fake()->optional()->sentence(),
            'purpose' => fake()->optional()->paragraph(),
            'preparation_notes' => fake()->optional()->paragraph(),
            'broadcast_instructions' => fake()->optional()->paragraph(),
            'status' => EpisodeStatus::Draft,
        ];
    }

    public function forShow(Show $show): static
    {
        return $this->state(['show_id' => $show->id]);
    }

    /**
     * A fixture already in the guarded "Ready" status. `Ready` cannot be set
     * through a normal model write (only the MakeEpisodeReady service may),
     * so this fixture reaches it with an event-free write after creation.
     */
    public function scheduled(): static
    {
        return $this
            ->state(['broadcast_at' => fake()->dateTimeBetween('+1 day', '+2 weeks')])
            ->afterCreating(function (Episode $episode): void {
                $episode->status = EpisodeStatus::Ready;
                $episode->saveQuietly();
            });
    }

    public function live(): static
    {
        return $this->state(['status' => EpisodeStatus::Live]);
    }
}
