<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Resolution\LogicalModelResolver;
use App\Filament\Resources\Episodes\Pages\RehearseEpisode;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Fakes\ThrowingTextProvider;
use Tests\TestCase;

class RehearseEpisodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function fake(): FakeTextProvider
    {
        return $this->app->make(FakeTextProvider::class);
    }

    /**
     * @return array{0: Episode, 1: AiPersona}
     */
    private function episodeWithPersona(): array
    {
        $episode = Episode::factory()->create([
            'title' => 'Kadının Toplumdaki Yeri',
            'main_topic' => 'Cahiliye döneminden İslam\'a',
        ]);
        $persona = AiPersona::factory()->create(['name' => 'Hikmet', 'ai_model' => 'default']);
        $episode->lineup()->create([
            'ai_persona_id' => $persona->id,
            'sort_order' => 0,
            'episode_instructions' => 'Sakin ol, kaynak göster.',
        ]);

        return [$episode, $persona];
    }

    public function test_only_admins_can_open_the_rehearsal_page(): void
    {
        $episode = Episode::factory()->create();
        $url = "/admin/episodes/{$episode->uuid}/rehearse";

        auth()->logout();
        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    }

    public function test_it_opens_by_the_episode_uuid_and_exposes_the_rehearsal_form(): void
    {
        [$episode] = $this->episodeWithPersona();

        Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->assertFormFieldExists('ai_persona_id')
            ->assertFormFieldExists('episode_topic_id')
            ->assertFormFieldExists('episode_question_id')
            ->assertFormFieldExists('presenter_question');
    }

    public function test_it_generates_a_response_through_the_fake_provider(): void
    {
        [$episode, $persona] = $this->episodeWithPersona();

        $component = Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $persona->id,
                'presenter_question' => 'Miras hukuku nasıl değişti?',
            ])
            ->call('generate')
            ->assertHasNoFormErrors();

        $this->assertNotNull($component->instance()->responseText);
        $this->assertStringContainsString('[fake:', (string) $component->instance()->responseText);

        $call = $this->fake()->lastCall();
        $this->assertNotNull($call);
        $this->assertSame('Miras hukuku nasıl değişti?', $call->messages->last()->content);
        $this->assertStringContainsString('BÖLÜM BAŞLIĞI: Kadının Toplumdaki Yeri', (string) $call->systemInstructions);
        $this->assertStringContainsString('BU BÖLÜME ÖZEL KARAKTER TALİMATLARI: Sakin ol, kaynak göster.', (string) $call->systemInstructions);
    }

    public function test_selecting_an_existing_question_populates_the_editable_presenter_question(): void
    {
        [$episode, $persona] = $this->episodeWithPersona();
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create(['title' => 'Miras']);
        $question = EpisodeQuestion::factory()->forTopic($topic)->create(['question' => 'Paylar nasıl belirlendi?']);

        Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $persona->id,
                'episode_topic_id' => $topic->id,
                'episode_question_id' => $question->id,
            ])
            ->assertSet('data.presenter_question', 'Paylar nasıl belirlendi?')
            // operator edits it before generating
            ->fillForm(['presenter_question' => 'Paylar nasıl belirlendi ve neden?'])
            ->call('generate')
            ->assertHasNoFormErrors();

        // The EDITED text is what was sent, not the stored question.
        $this->assertSame(
            'Paylar nasıl belirlendi ve neden?',
            $this->fake()->lastCall()?->messages->last()->content,
        );
        $this->assertStringContainsString('SEÇİLİ KONU: Miras', (string) $this->fake()->lastCall()?->systemInstructions);
    }

    public function test_a_persona_outside_the_lineup_cannot_be_selected_or_generated_against(): void
    {
        [$episode] = $this->episodeWithPersona();
        $intruder = AiPersona::factory()->create(['name' => 'Yabancı']);

        // The persona options are computed server-side from THIS episode's
        // line-up, so a foreign id is rejected as an invalid selection and the
        // provider is never reached.
        Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $intruder->id,
                'presenter_question' => 'Soru?',
            ])
            ->call('generate')
            ->assertHasFormErrors(['ai_persona_id']);

        $this->assertFalse($this->fake()->wasCalled());
    }

    public function test_a_topic_from_another_episode_cannot_be_selected(): void
    {
        [$episode, $persona] = $this->episodeWithPersona();
        $foreignTopic = EpisodeTopic::factory()->forEpisode(Episode::factory()->create())->create();

        Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $persona->id,
                'episode_topic_id' => $foreignTopic->id,
                'presenter_question' => 'Soru?',
            ])
            ->call('generate')
            ->assertHasFormErrors(['episode_topic_id']);

        $this->assertFalse($this->fake()->wasCalled());
    }

    public function test_a_question_not_under_the_selected_topic_cannot_be_selected(): void
    {
        [$episode, $persona] = $this->episodeWithPersona();
        $topicA = EpisodeTopic::factory()->forEpisode($episode)->create(['title' => 'A']);
        $topicB = EpisodeTopic::factory()->forEpisode($episode)->create(['title' => 'B']);
        $questionUnderB = EpisodeQuestion::factory()->forTopic($topicB)->create();

        // Question options are scoped to the selected topic; a question from a
        // different topic is not a valid choice.
        Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $persona->id,
                'episode_topic_id' => $topicA->id,
                'presenter_question' => 'Soru?',
            ])
            ->set('data.episode_question_id', $questionUnderB->id)
            ->call('generate')
            ->assertHasFormErrors(['episode_question_id']);

        $this->assertFalse($this->fake()->wasCalled());
    }

    public function test_a_provider_failure_degrades_to_a_safe_notification(): void
    {
        [$episode, $persona] = $this->episodeWithPersona();

        config()->set('ai.text.drivers.boom', ThrowingTextProvider::class);
        config()->set('ai.text.providers.default.driver', 'boom');
        $this->app->forgetInstance(LogicalModelResolver::class);

        $component = Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $persona->id,
                'presenter_question' => 'Soru?',
            ])
            ->call('generate')
            ->assertNotified();

        $this->assertNull($component->instance()->responseText);
    }

    public function test_provider_credentials_are_never_rendered_on_the_page(): void
    {
        [$episode, $persona] = $this->episodeWithPersona();
        config()->set('ai.text.connections.openai.api_key', 'leak-canary-value');

        $this->get("/admin/episodes/{$episode->uuid}/rehearse")
            ->assertOk()
            ->assertDontSee('leak-canary-value');

        $component = Livewire::test(RehearseEpisode::class, ['record' => $episode->getRouteKey()])
            ->fillForm([
                'ai_persona_id' => $persona->id,
                'presenter_question' => 'Soru?',
            ])
            ->call('generate');

        $this->assertStringNotContainsString('leak-canary-value', (string) $component->instance()->responseText);
        $component->assertDontSee('leak-canary-value');
    }

    public function test_the_submit_control_is_disabled_while_a_generation_is_running(): void
    {
        [$episode] = $this->episodeWithPersona();

        $this->get("/admin/episodes/{$episode->uuid}/rehearse")
            ->assertOk()
            ->assertSee('wire:target="generate"', escape: false)
            ->assertSee('wire:loading.attr="disabled"', escape: false);
    }
}
