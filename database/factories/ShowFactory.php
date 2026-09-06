<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShowStatus;
use App\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    protected $model = Show::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake()->optional()->paragraph(),
            'status' => ShowStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => ShowStatus::Active]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ShowStatus::Archived]);
    }
}
