<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\EpisodeStatus;
use App\Filament\Resources\Episodes\Pages\EditEpisode;
use App\Filament\Resources\Episodes\Pages\ListEpisodes;
use App\Filament\Resources\Episodes\Pages\PrepareEpisode;
use App\Filament\Resources\Episodes\RelationManagers\AiPersonasRelationManager;
use App\Filament\Resources\Episodes\RelationManagers\TopicsRelationManager;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrepareEpisodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function preparedEpisode(): Episode
    {
        $episode = Episode::factory()->create([
            'title' => 'Energy Debate',
            'main_topic' => 'Grid decarbonisation',
            'broadcast_at' => now()->addWeek(),
            'ai_objective' => 'Stress-test both sides.',
            'must_cover_points' => 'Cost. Timeline.',
        ]);
        $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 0]);
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create();
        EpisodeQuestion::factory()->forTopic($topic)->create();

        return $episode->fresh() ?? $episode;
    }

    public function test_only_admins_can_open_the_preparation_workspace(): void
    {
        $episode = Episode::factory()->create();
        $url = "/admin/episodes/{$episode->uuid}/prepare";

        auth()->logout();
        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    }

    public function test_the_workspace_opens_the_episode_by_its_uuid_route_key(): void
    {
        $episode = Episode::factory()->create(['title' => 'Route Key Test']);

        $this->get("/admin/episodes/{$episode->uuid}/prepare")
            ->assertOk()
            ->assertSee('Route Key Test');

        // The numeric id must not resolve.
        $this->get("/admin/episodes/{$episode->id}/prepare")->assertNotFound();
    }

    public function test_the_presenter_brief_persists_from_the_workspace(): void
    {
        $episode = Episode::factory()->create();

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'opening_notes' => 'Welcome the audience warmly.',
                'key_points' => 'Three cost scenarios.',
                'questions_to_push' => 'Who pays?',
                'closing_notes' => 'Tease next week.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('episodes', [
            'id' => $episode->id,
            'opening_notes' => 'Welcome the audience warmly.',
            'closing_notes' => 'Tease next week.',
        ]);
    }

    public function test_the_ai_brief_persists_from_the_workspace(): void
    {
        $episode = Episode::factory()->create();

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_objective' => 'Keep both AI voices adversarial but civil.',
                'ai_tone_override' => 'measured',
                'must_cover_points' => 'Grid stability. Jobs.',
                'avoid_points' => 'Party-political point scoring.',
                'response_length_guidance' => 'moderate',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('episodes', [
            'id' => $episode->id,
            'ai_objective' => 'Keep both AI voices adversarial but civil.',
            'response_length_guidance' => 'moderate',
        ]);
    }

    public function test_topics_are_manageable_from_the_workspace_relation_manager(): void
    {
        $episode = Episode::factory()->create();

        Livewire::test(TopicsRelationManager::class, [
            'ownerRecord' => $episode,
            'pageClass' => PrepareEpisode::class,
        ])->callTableAction('create', data: ['title' => 'Opening statement rules', 'sort_order' => 0]);

        $this->assertDatabaseHas('episode_topics', [
            'episode_id' => $episode->id,
            'title' => 'Opening statement rules',
        ]);
    }

    public function test_the_ai_line_up_is_manageable_from_the_workspace_relation_manager(): void
    {
        $episode = Episode::factory()->create();
        $persona = AiPersona::factory()->active()->create();

        Livewire::test(AiPersonasRelationManager::class, [
            'ownerRecord' => $episode,
            'pageClass' => PrepareEpisode::class,
        ])->callTableAction('create', data: [
            'ai_persona_id' => $persona->id,
            'sort_order' => 0,
            'episode_instructions' => 'Open sceptical, warm up later.',
        ]);

        $this->assertSame(
            'Open sceptical, warm up later.',
            $episode->lineup()->where('ai_persona_id', $persona->id)->value('episode_instructions'),
        );
    }

    public function test_the_readiness_panel_reports_an_incomplete_episode_as_not_ready(): void
    {
        $episode = Episode::factory()->create(['main_topic' => null, 'broadcast_at' => null]);

        $this->get("/admin/episodes/{$episode->uuid}/prepare")
            ->assertOk()
            ->assertSee('yayına hazır değil')
            ->assertSee('En az bir tartışma başlığı var');
    }

    public function test_make_ready_action_does_not_transition_an_incomplete_episode(): void
    {
        $episode = Episode::factory()->create(['main_topic' => null]);

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->callAction('makeReady');

        $this->assertSame(EpisodeStatus::Draft, $episode->fresh()->status);
    }

    public function test_make_ready_action_transitions_a_fully_prepared_episode(): void
    {
        $episode = $this->preparedEpisode();

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->callAction('makeReady');

        $this->assertSame(EpisodeStatus::Ready, $episode->fresh()->status);
    }

    public function test_make_ready_persists_unsaved_form_edits_before_assessing(): void
    {
        // Everything ready EXCEPT the two AI-brief scalars, which the operator
        // will type into the form and then immediately hit "Yayına Hazırla"
        // without first pressing Save.
        $episode = Episode::factory()->create([
            'title' => 'Energy Debate',
            'main_topic' => 'Grid decarbonisation',
            'broadcast_at' => now()->addWeek(),
            'ai_objective' => null,
            'must_cover_points' => null,
        ]);
        $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 0]);
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create();
        EpisodeQuestion::factory()->forTopic($topic)->create();

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_objective' => 'Stress-test both sides.',
                'must_cover_points' => 'Cost. Timeline.',
            ])
            ->callAction('makeReady')
            ->assertHasNoFormErrors();

        $fresh = $episode->fresh();
        $this->assertSame(EpisodeStatus::Ready, $fresh->status);
        $this->assertSame('Stress-test both sides.', $fresh->ai_objective);
        $this->assertSame('Cost. Timeline.', $fresh->must_cover_points);
    }

    public function test_the_readiness_panel_reacts_to_unsaved_form_state(): void
    {
        // Complete in every respect EXCEPT main_topic, which is still unsaved.
        $episode = Episode::factory()->create([
            'title' => 'Energy Debate',
            'main_topic' => null,
            'broadcast_at' => now()->addWeek(),
            'ai_objective' => 'Stress-test both sides.',
            'must_cover_points' => 'Cost. Timeline.',
        ]);
        $episode->lineup()->create(['ai_persona_id' => AiPersona::factory()->create()->id, 'sort_order' => 0]);
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create();
        EpisodeQuestion::factory()->forTopic($topic)->create();

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->assertSee('Bu bölüm henüz yayına hazır değil.')
            ->fillForm(['main_topic' => 'A freshly typed, unsaved topic'])
            ->assertSee('Bu bölüm yayına hazır.')
            ->assertDontSee('Bu bölüm henüz yayına hazır değil.');
    }

    public function test_make_ready_action_is_unavailable_for_a_terminal_episode(): void
    {
        $episode = $this->preparedEpisode();
        $episode->forceFill(['status' => EpisodeStatus::Completed])->saveQuietly();

        Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()])
            ->assertActionHidden('makeReady');
    }

    public function test_make_ready_surfaces_an_invalid_transition_when_the_episode_was_archived_after_the_page_loaded(): void
    {
        $episode = $this->preparedEpisode();

        $component = Livewire::test(PrepareEpisode::class, ['record' => $episode->getRouteKey()]);

        // Another operator archives the episode while this page is open.
        $episode->forceFill(['status' => EpisodeStatus::Archived])->saveQuietly();

        $component->callAction('makeReady');

        // The stale page must not have pushed it to Ready.
        $this->assertSame(EpisodeStatus::Archived, $episode->fresh()->status);
    }

    public function test_the_duplicate_action_creates_a_copy_and_redirects_to_its_workspace(): void
    {
        $source = Episode::factory()->create();

        Livewire::test(ListEpisodes::class)
            ->callTableAction('duplicate', $source, data: ['copy_instructions' => false])
            ->assertRedirect();

        $copy = Episode::where('id', '!=', $source->id)->sole();
        $this->assertSame($source->show_id, $copy->show_id);
        $this->assertSame(EpisodeStatus::Draft, $copy->status);
    }

    public function test_the_status_select_on_the_normal_form_does_not_offer_ready(): void
    {
        $episode = Episode::factory()->create();

        Livewire::test(EditEpisode::class, ['record' => $episode->getRouteKey()])
            ->assertFormFieldExists('status')
            ->fillForm(['status' => EpisodeStatus::Ready->value])
            ->call('save')
            ->assertHasFormErrors(['status']);
    }
}
