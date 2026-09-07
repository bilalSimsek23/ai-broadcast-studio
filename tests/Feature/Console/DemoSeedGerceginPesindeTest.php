<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use Database\Seeders\LocalTestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedGerceginPesindeTest extends TestCase
{
    use RefreshDatabase;

    private function assertExpectedCounts(): void
    {
        $this->assertDatabaseCount('shows', 1);
        $this->assertDatabaseCount('ai_personas', 1);
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('episode_ai_persona', 1);
        $this->assertDatabaseCount('episode_topics', 6);
        $this->assertDatabaseCount('episode_questions', 24);
    }

    public function test_it_creates_the_expected_records_and_makes_the_episode_ready(): void
    {
        $this->artisan('demo:seed-gercegin-pesinde')->assertSuccessful();

        $this->assertExpectedCounts();

        $this->assertDatabaseHas('shows', ['slug' => 'gercegin-pesinde', 'name' => 'Gerçeğin Peşinde']);
        $this->assertDatabaseHas('ai_personas', ['name' => 'Hikmet']);

        /** @var Episode $episode */
        $episode = Episode::query()->firstOrFail();
        $this->assertSame('Cahiliye Döneminden İslam\'a: Kadının Toplumdaki Yeri', $episode->title);
        $this->assertSame(EpisodeStatus::Ready, $episode->status);
        $this->assertSame('gercegin-pesinde', $episode->show()->value('slug'));
        $this->assertSame(1, $episode->lineup()->count());
        // 4 questions under every one of the 6 topics.
        $this->assertSame([4, 4, 4, 4, 4, 4], $episode->topics()->orderBy('sort_order')
            ->withCount('questions')->pluck('questions_count')->all());
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('demo:seed-gercegin-pesinde')->assertSuccessful();
        $this->artisan('demo:seed-gercegin-pesinde')->assertSuccessful();
        $this->artisan('demo:seed-gercegin-pesinde')->assertSuccessful();

        $this->assertExpectedCounts();
        $this->assertSame(EpisodeStatus::Ready, Episode::query()->firstOrFail()->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('demo:seed-gercegin-pesinde', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertDatabaseCount('shows', 0);
        $this->assertDatabaseCount('ai_personas', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('episode_topics', 0);
        $this->assertDatabaseCount('episode_questions', 0);
    }

    public function test_the_local_seeder_delegates_to_the_command(): void
    {
        $this->seed(LocalTestDataSeeder::class);

        $this->assertExpectedCounts();
    }

    /**
     * SQLite (test engine) silently accepts over-long VARCHAR values; production
     * MySQL rejects them (SQLSTATE 22001). Guard every seeded VARCHAR(255)
     * column here.
     */
    public function test_seeded_varchar_columns_fit_the_255_char_limit(): void
    {
        $this->artisan('demo:seed-gercegin-pesinde')->assertSuccessful();

        $values = [
            'shows.name' => \DB::table('shows')->value('name'),
            'shows.slug' => \DB::table('shows')->value('slug'),
            'ai_personas.name' => \DB::table('ai_personas')->value('name'),
            'ai_personas.title' => \DB::table('ai_personas')->value('title'),
            'ai_personas.speaking_style' => \DB::table('ai_personas')->value('speaking_style'),
            'episodes.title' => \DB::table('episodes')->value('title'),
            'episodes.ai_tone_override' => \DB::table('episodes')->value('ai_tone_override'),
            'episodes.response_length_guidance' => \DB::table('episodes')->value('response_length_guidance'),
        ];

        foreach (\DB::table('episode_topics')->pluck('title') as $i => $title) {
            $values["episode_topics[$i].title"] = $title;
        }

        foreach ($values as $column => $value) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $value), "$column exceeds VARCHAR(255)");
        }
    }
}
