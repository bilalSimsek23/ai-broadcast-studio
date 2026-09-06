<?php

declare(strict_types=1);

namespace Tests\Feature\Application;

use App\Application\Episodes\DuplicateEpisode;
use App\Enums\EpisodeStatus;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateEpisodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_copies_the_show_and_line_up_but_not_the_editorial_content(): void
    {
        $source = Episode::factory()->scheduled()->create([
            'main_topic' => 'This week only',
            'episode_number' => 12,
            'broadcast_instructions' => 'Keep segments tight.',
            'ai_objective' => 'week-specific objective',
        ]);
        $a = AiPersona::factory()->create();
        $b = AiPersona::factory()->create();
        $source->lineup()->create(['ai_persona_id' => $a->id, 'sort_order' => 0, 'episode_instructions' => 'lead']);
        $source->lineup()->create(['ai_persona_id' => $b->id, 'sort_order' => 1, 'episode_instructions' => 'oppose']);
        $topic = EpisodeTopic::factory()->forEpisode($source)->create();
        EpisodeQuestion::factory()->forTopic($topic)->create();

        $copy = app(DuplicateEpisode::class)($source);

        // Copied
        $this->assertSame($source->show_id, $copy->show_id);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $copy->lineup()->pluck('ai_persona_id')->all(),
        );
        $this->assertSame('lead', $copy->lineup()->where('ai_persona_id', $a->id)->value('episode_instructions'));

        // NOT copied
        $this->assertSame(EpisodeStatus::Draft, $copy->status);
        $this->assertNull($copy->broadcast_at);
        $this->assertNull($copy->main_topic);
        $this->assertNull($copy->episode_number);
        $this->assertNull($copy->ai_objective);
        $this->assertNull($copy->broadcast_instructions);
        $this->assertSame(0, $copy->topics()->count());
    }

    public function test_broadcast_instructions_are_copied_only_when_asked(): void
    {
        $source = Episode::factory()->create(['broadcast_instructions' => 'House style.']);

        $this->assertSame('House style.', app(DuplicateEpisode::class)($source, copyBroadcastInstructions: true)->broadcast_instructions);
        $this->assertNull(app(DuplicateEpisode::class)($source, copyBroadcastInstructions: false)->broadcast_instructions);
    }

    public function test_a_max_length_source_title_yields_a_valid_copy_title(): void
    {
        $source = Episode::factory()->create(['title' => str_repeat('a', 255)]);

        $copy = app(DuplicateEpisode::class)($source);

        $this->assertLessThanOrEqual(255, mb_strlen($copy->title));
        $this->assertStringEndsWith('(kopya)', $copy->title);
    }

    public function test_it_copies_the_persisted_line_up_not_a_stale_cached_relation(): void
    {
        $source = Episode::factory()->create();
        $a = AiPersona::factory()->create();
        $source->lineup()->create(['ai_persona_id' => $a->id, 'sort_order' => 0, 'episode_instructions' => 'old']);

        // Warm a stale cache on this instance.
        $source->load('lineup');
        $this->assertCount(1, $source->lineup);

        // The line-up changes through another handle on the same episode.
        $b = AiPersona::factory()->create();
        Episode::query()->whereKey($source->getKey())->firstOrFail()
            ->lineup()->create(['ai_persona_id' => $b->id, 'sort_order' => 1, 'episode_instructions' => 'new']);

        $copy = app(DuplicateEpisode::class)($source);

        // The copy reflects the persisted line-up (2 slots), not the cache (1).
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $copy->lineup()->pluck('ai_persona_id')->all(),
        );
    }
}
