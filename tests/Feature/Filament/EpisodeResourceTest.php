<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\EpisodeStatus;
use App\Filament\Resources\Episodes\EpisodeResource;
use App\Filament\Resources\Episodes\Pages\CreateEpisode;
use App\Filament\Resources\Episodes\Pages\EditEpisode;
use App\Filament\Resources\Episodes\RelationManagers\AiPersonasRelationManager;
use App\Filament\Resources\Episodes\RelationManagers\TopicsRelationManager;
use App\Filament\Resources\EpisodeTopics\Pages\EditEpisodeTopic;
use App\Filament\Resources\EpisodeTopics\RelationManagers\QuestionsRelationManager;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeTopic;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class EpisodeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_an_episode_is_created_and_bound_to_a_show(): void
    {
        $show = Show::factory()->create();

        Livewire::test(CreateEpisode::class)
            ->fillForm([
                'show_id' => $show->id,
                'title' => 'Opening Night',
                'episode_number' => 1,
                'status' => EpisodeStatus::Preparing->value,
                'main_topic' => 'Energy policy',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $episode = Episode::whereTitle('Opening Night')->sole();
        $this->assertSame($show->id, $episode->show_id);
        $this->assertSame(EpisodeStatus::Preparing, $episode->status);
    }

    public function test_multiple_ai_personas_can_be_assigned_to_an_episode(): void
    {
        $episode = Episode::factory()->create();
        $host = AiPersona::factory()->active()->create();
        $guest = AiPersona::factory()->active()->create();

        $rm = Livewire::test(AiPersonasRelationManager::class, [
            'ownerRecord' => $episode,
            'pageClass' => EditEpisode::class,
        ]);

        $rm->callTableAction('create', data: ['ai_persona_id' => $host->id, 'sort_order' => 0, 'episode_instructions' => 'lead']);
        $rm->callTableAction('create', data: ['ai_persona_id' => $guest->id, 'sort_order' => 1, 'episode_instructions' => 'oppose']);

        $this->assertEqualsCanonicalizing(
            [$host->id, $guest->id],
            $episode->lineup()->pluck('ai_persona_id')->all(),
        );
        $this->assertSame('lead', $episode->lineup()->where('ai_persona_id', $host->id)->value('episode_instructions'));
    }

    public function test_the_same_persona_cannot_be_assigned_twice_and_the_ui_fails_gracefully(): void
    {
        $episode = Episode::factory()->create();
        $persona = AiPersona::factory()->active()->create();
        $episode->lineup()->create(['ai_persona_id' => $persona->id, 'sort_order' => 0]);

        // Race: the persona is already assigned but the form still submits it.
        // The transaction-wrapped create catches the unique violation.
        Livewire::test(AiPersonasRelationManager::class, [
            'ownerRecord' => $episode,
            'pageClass' => EditEpisode::class,
        ])->callTableAction('create', data: ['ai_persona_id' => $persona->id, 'sort_order' => 5]);

        $this->assertSame(1, $episode->lineup()->count());
    }

    public function test_the_line_up_can_be_reordered_by_sort_order(): void
    {
        $episode = Episode::factory()->create();
        $a = $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 0]);
        $b = $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 1]);

        Livewire::test(AiPersonasRelationManager::class, [
            'ownerRecord' => $episode,
            'pageClass' => EditEpisode::class,
        ])->call('reorderTable', [(string) $b->id, (string) $a->id]);

        // b now sorts before a, and the values are strictly ascending on the
        // episode_ai_persona table (not on ai_personas, which has no such column).
        $this->assertSame([$b->id, $a->id], $episode->lineup()->orderBy('sort_order')->pluck('id')->all());
        $this->assertLessThan($a->refresh()->sort_order, $b->refresh()->sort_order);
        $this->assertFalse(Schema::hasColumn('ai_personas', 'sort_order'));
    }

    public function test_a_topic_can_be_created_from_the_episode_edit_screen(): void
    {
        $episode = Episode::factory()->create();

        Livewire::test(TopicsRelationManager::class, [
            'ownerRecord' => $episode,
            'pageClass' => EditEpisode::class,
        ])->callTableAction('create', data: [
            'title' => 'Is the policy affordable?',
            'description' => 'Cost side of the debate.',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('episode_topics', [
            'episode_id' => $episode->id,
            'title' => 'Is the policy affordable?',
        ]);
    }

    public function test_a_question_can_be_created_under_a_topic(): void
    {
        $topic = EpisodeTopic::factory()->create();

        Livewire::test(QuestionsRelationManager::class, [
            'ownerRecord' => $topic,
            'pageClass' => EditEpisodeTopic::class,
        ])->callTableAction('create', data: [
            'question' => 'What is the 10-year cost?',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('episode_questions', [
            'episode_topic_id' => $topic->id,
            'question' => 'What is the 10-year cost?',
        ]);
    }

    public function test_the_back_to_episode_link_targets_the_episode_by_its_uuid(): void
    {
        $topic = EpisodeTopic::factory()->create();
        $episode = $topic->episode()->firstOrFail();

        $url = EpisodeResource::getUrl('edit', ['record' => $episode]);

        $this->assertStringContainsString($episode->uuid, $url);
        $this->assertStringNotContainsString("/{$episode->id}/edit", $url);
        $this->get($url)->assertOk();
    }
}
