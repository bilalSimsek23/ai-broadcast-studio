<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Providers\Fake\FakeRealtimeVoiceProvider;
use App\AI\Providers\OpenAi\OpenAiRealtimeProvider;
use App\Enums\EpisodeStatus;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Episode-bound production flow for POST /studio/live/session: a Ready episode
 * (+ persona) must be selected, is validated server-side, and its prepared
 * content becomes the realtime session instructions.
 */
class StudioLiveEpisodeSessionTest extends TestCase
{
    use RefreshDatabase;

    private const STANDING_KEY = 'CANARY-openai-standing-key-must-not-leak';

    protected function setUp(): void
    {
        parent::setUp();
        // Production default: a no-episode session is refused.
        config()->set('ai.realtime.allow_standalone_session', false);
        $this->actingAs(User::factory()->admin()->create());
    }

    private function fake(): FakeRealtimeVoiceProvider
    {
        return $this->app->make(FakeRealtimeVoiceProvider::class);
    }

    private function persona(array $attributes = []): AiPersona
    {
        return AiPersona::factory()->create(array_merge([
            'name' => 'Hikmet',
            'title' => 'Tarih ve Medeniyet Araştırmacısı',
            'expertise' => 'İslam tarihi',
            'personality' => 'Sakin',
            'speaking_style' => 'Sade',
            'system_prompt' => 'PERSONA-SYSTEM-PROMPT-MARKER',
        ], $attributes));
    }

    /**
     * @param  list<AiPersona>  $personas
     */
    private function readyEpisode(array $personas): Episode
    {
        $episode = Episode::factory()->scheduled()->create([
            'title' => 'Cahiliye Döneminden İslam\'a: Kadının Toplumdaki Yeri',
            'main_topic' => 'İslam öncesi Arap toplumlarında kadının konumu',
            'ai_objective' => 'Karşı argümanları nazikçe zorla.',
            'must_cover_points' => 'Miras hakları. Evlilik.',
            'avoid_points' => 'Siyasi polemik.',
            'opening_notes' => 'PRESENTER-ONLY-OPENING',
            'closing_notes' => 'PRESENTER-ONLY-CLOSING',
        ]);
        $episode->show()->update(['name' => 'Gerçeğin Peşinde']);

        foreach ($personas as $i => $persona) {
            $episode->lineup()->create([
                'ai_persona_id' => $persona->id,
                'sort_order' => $i,
                'episode_instructions' => $i === 0 ? 'LINEUP-INSTRUCTION-MARKER' : 'diğer',
            ]);
        }

        $topic = EpisodeTopic::factory()->forEpisode($episode)->create([
            'title' => 'Cahiliye Döneminde Kadın',
            'presenter_notes' => 'PRESENTER-ONLY-TOPIC',
            'sort_order' => 1,
        ]);
        EpisodeQuestion::factory()->forTopic($topic)->create([
            'question' => 'İslam\'dan önce kadının hiçbir hakkı yok muydu?',
            'presenter_notes' => 'PRESENTER-ONLY-QUESTION',
            'sort_order' => 1,
        ]);
        EpisodeTopic::factory()->forEpisode($episode)->create(['title' => 'Miras ve Mülkiyet', 'sort_order' => 2]);

        return $episode->refresh();
    }

    private function useOpenAiDriver(): void
    {
        config()->set('ai.realtime.driver', 'openai');
        config()->set('ai.realtime.connections.openai', [
            'api_key' => self::STANDING_KEY,
            'base_url' => 'https://api.openai.test/v1',
            'model' => 'gpt-realtime',
            'voice' => 'cedar',
            'timeout' => 15,
            'connect_timeout' => 10,
        ]);
        $this->app->forgetInstance(RealtimeVoiceProvider::class);
        $this->app->forgetInstance(OpenAiRealtimeProvider::class);
    }

    public function test_a_session_cannot_be_opened_without_an_episode(): void
    {
        $this->postJson('/studio/live/session')
            ->assertStatus(422)
            ->assertJsonPath('error', 'episode_required');
    }

    public function test_an_unknown_episode_is_refused(): void
    {
        $this->postJson('/studio/live/session', ['episode' => 'no-such-uuid'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'episode_not_found');
    }

    public function test_a_non_ready_episode_is_refused(): void
    {
        $draft = Episode::factory()->create(['status' => EpisodeStatus::Preparing]);

        $this->postJson('/studio/live/session', ['episode' => $draft->uuid])
            ->assertStatus(422)
            ->assertJsonPath('error', 'episode_not_ready');
    }

    public function test_a_persona_outside_the_lineup_is_refused(): void
    {
        $episode = $this->readyEpisode([$this->persona()]);
        $intruder = AiPersona::factory()->create();

        $this->postJson('/studio/live/session', ['episode' => $episode->uuid, 'persona' => $intruder->uuid])
            ->assertStatus(422)
            ->assertJsonPath('error', 'persona_not_in_lineup');
    }

    public function test_a_multi_persona_episode_needs_an_explicit_persona(): void
    {
        $episode = $this->readyEpisode([$this->persona(['name' => 'Hikmet']), $this->persona(['name' => 'Derya'])]);

        $this->postJson('/studio/live/session', ['episode' => $episode->uuid])
            ->assertStatus(422)
            ->assertJsonPath('error', 'persona_required');
    }

    public function test_a_single_persona_episode_builds_instructions_from_prepared_content(): void
    {
        $episode = $this->readyEpisode([$this->persona()]);

        $this->postJson('/studio/live/session', ['episode' => $episode->uuid])->assertOk();

        $instructions = (string) $this->fake()->lastCall()?->instructions;

        foreach ([
            'Gerçeğin Peşinde',
            'Hikmet',
            'Tarih ve Medeniyet Araştırmacısı',
            'Cahiliye Döneminden İslam\'a: Kadının Toplumdaki Yeri',
            'İslam öncesi Arap toplumlarında kadının konumu',
            'Karşı argümanları nazikçe zorla.',
            'Miras hakları. Evlilik.',
            'Siyasi polemik.',
            'LINEUP-INSTRUCTION-MARKER',
            'PERSONA-SYSTEM-PROMPT-MARKER',
            'TARTIŞMA BAŞLIĞI: Cahiliye Döneminde Kadın',
            'TARTIŞMA BAŞLIĞI: Miras ve Mülkiyet',
            'SORU: İslam\'dan önce kadının hiçbir hakkı yok muydu?',
            'CANLI YAYIN GÖREVİ:',
        ] as $needle) {
            $this->assertStringContainsString($needle, $instructions);
        }

        // Presenter-only content is NOT sent to the AI.
        $this->assertStringNotContainsString('PRESENTER-ONLY-OPENING', $instructions);
        $this->assertStringNotContainsString('PRESENTER-ONLY-CLOSING', $instructions);
        $this->assertStringNotContainsString('PRESENTER-ONLY-TOPIC', $instructions);
        $this->assertStringNotContainsString('PRESENTER-ONLY-QUESTION', $instructions);
    }

    public function test_a_multi_persona_episode_uses_the_chosen_persona(): void
    {
        $episode = $this->readyEpisode([
            $this->persona(['name' => 'Hikmet', 'system_prompt' => 'HIKMET-PROMPT']),
            $this->persona(['name' => 'Derya', 'system_prompt' => 'DERYA-PROMPT']),
        ]);
        $derya = AiPersona::query()->where('name', 'Derya')->firstOrFail();

        $this->postJson('/studio/live/session', ['episode' => $episode->uuid, 'persona' => $derya->uuid])->assertOk();

        $instructions = (string) $this->fake()->lastCall()?->instructions;
        $this->assertStringContainsString('AI KARAKTER KİMLİĞİ: Derya', $instructions);
        $this->assertStringContainsString('DERYA-PROMPT', $instructions);
        $this->assertStringNotContainsString('HIKMET-PROMPT', $instructions);
    }

    public function test_the_openai_driver_sends_the_episode_briefing_and_keeps_the_standing_key(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response(
                ['value' => 'ek_live_x', 'expires_at' => 1_900_000_000, 'session' => ['model' => 'gpt-realtime']],
            ),
        ]);
        $this->useOpenAiDriver();
        $episode = $this->readyEpisode([$this->persona()]);

        $response = $this->postJson('/studio/live/session', ['episode' => $episode->uuid])->assertOk();

        $this->assertStringNotContainsString(self::STANDING_KEY, $response->getContent() ?: '');

        Http::assertSent(function (Request $request): bool {
            $instructions = $request->data()['session']['instructions'];

            return $request->hasHeader('Authorization', 'Bearer '.self::STANDING_KEY)
                && str_contains($instructions, 'Gerçeğin Peşinde')
                && str_contains($instructions, 'Cahiliye Döneminden İslam\'a: Kadının Toplumdaki Yeri')
                && str_contains($instructions, 'CANLI YAYIN GÖREVİ:');
        });
    }

    public function test_a_standalone_session_is_still_possible_when_explicitly_allowed(): void
    {
        config()->set('ai.realtime.allow_standalone_session', true);
        config()->set('ai.realtime.instructions', 'STANDALONE-FALLBACK-BRIEF');

        $this->postJson('/studio/live/session')->assertOk();

        $this->assertSame('STANDALONE-FALLBACK-BRIEF', $this->fake()->lastCall()?->instructions);
    }
}
