<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Realtime\ResolveStudioEpisode;
use App\AI\Realtime\StudioEpisodeUnavailable;
use App\Enums\EpisodeStatus;
use App\Models\AiPersona;
use App\Models\Episode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveStudioEpisodeTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(): ResolveStudioEpisode
    {
        return $this->app->make(ResolveStudioEpisode::class);
    }

    private function readyEpisodeWithPersonas(int $count): Episode
    {
        $episode = Episode::factory()->scheduled()->create();

        for ($i = 0; $i < $count; $i++) {
            $episode->lineup()->create([
                'ai_persona_id' => AiPersona::factory()->create(['name' => 'Karakter '.($i + 1)])->id,
                'sort_order' => $i,
            ]);
        }

        return $episode->refresh();
    }

    private function assertReason(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected '.StudioEpisodeUnavailable::class.' ('.$reason.')');
        } catch (StudioEpisodeUnavailable $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_a_missing_episode_uuid_is_rejected(): void
    {
        $this->assertReason('episode_required', fn () => ($this->resolve())(null));
        $this->assertReason('episode_required', fn () => ($this->resolve())('   '));
    }

    public function test_an_unknown_episode_uuid_is_rejected(): void
    {
        $this->assertReason('episode_not_found', fn () => ($this->resolve())('00000000-0000-0000-0000-000000000000'));
    }

    public function test_a_non_ready_episode_is_rejected(): void
    {
        $draft = Episode::factory()->create(['status' => EpisodeStatus::Draft]);
        $preparing = Episode::factory()->create(['status' => EpisodeStatus::Preparing]);

        $this->assertReason('episode_not_ready', fn () => ($this->resolve())($draft->uuid));
        $this->assertReason('episode_not_ready', fn () => ($this->resolve())($preparing->uuid));
    }

    public function test_a_single_persona_lineup_is_auto_selected(): void
    {
        $episode = $this->readyEpisodeWithPersonas(1);

        $context = ($this->resolve())($episode->uuid);

        $this->assertSame($episode->uuid, $context->episode->uuid);
        $this->assertSame('Karakter 1', $context->persona->name);
        $this->assertSame($context->persona->id, $context->slot->ai_persona_id);
        // eager-loaded for the briefing
        $this->assertTrue($context->episode->relationLoaded('show'));
        $this->assertTrue($context->episode->relationLoaded('topics'));
    }

    public function test_a_multi_persona_lineup_requires_an_explicit_persona(): void
    {
        $episode = $this->readyEpisodeWithPersonas(2);

        $this->assertReason('persona_required', fn () => ($this->resolve())($episode->uuid));
    }

    public function test_a_multi_persona_lineup_uses_the_chosen_persona(): void
    {
        $episode = $this->readyEpisodeWithPersonas(2);
        $second = $episode->lineup()->with('aiPersona')->orderBy('sort_order')->get()->last()->aiPersona;

        $context = ($this->resolve())($episode->uuid, $second->uuid);

        $this->assertSame($second->uuid, $context->persona->uuid);
    }

    public function test_a_persona_outside_the_lineup_is_rejected(): void
    {
        $episode = $this->readyEpisodeWithPersonas(1);
        $intruder = AiPersona::factory()->create();

        $this->assertReason(
            'persona_not_in_lineup',
            fn () => ($this->resolve())($episode->uuid, $intruder->uuid),
        );
    }
}
