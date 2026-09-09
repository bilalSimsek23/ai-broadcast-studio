<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Providers\OpenAi\OpenAiRealtimeProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * These cases exercise session MECHANICS (auth, rate limit, audio constraints,
 * session length, voice, upstream failure) via the STANDALONE fallback path
 * (no episode bound). Episode-bound production behaviour is
 * {@see StudioLiveEpisodeSessionTest}.
 */
class StudioLiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private const STANDING_KEY = 'CANARY-openai-standing-key-must-not-leak';

    protected function setUp(): void
    {
        parent::setUp();

        // Allow a no-episode session so these mechanics tests do not each need
        // a full Ready-episode fixture.
        config()->set('ai.realtime.allow_standalone_session', true);
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    private function useOpenAiDriver(): void
    {
        config()->set('ai.realtime.driver', 'openai');
        config()->set('ai.realtime.instructions', 'Stüdyo brifingi: Türkçe tartış.');
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

    public function test_a_guest_cannot_mint_a_session(): void
    {
        $this->postJson('/studio/live/session')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_a_non_admin_cannot_mint_a_session(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson('/studio/live/session')->assertForbidden();
    }

    public function test_the_default_fake_driver_returns_a_usable_session_and_never_a_standing_key(): void
    {
        config()->set('ai.realtime.connections.openai.api_key', self::STANDING_KEY);
        $this->actingAsAdmin();

        $response = $this->postJson('/studio/live/session')->assertOk();

        $response->assertJsonStructure([
            'client_secret', 'expires_at', 'model', 'voice', 'session_max_seconds', 'webrtc_url',
            'audio_constraints' => ['echoCancellation', 'noiseSuppression', 'autoGainControl'],
        ]);
        // getUserMedia constraints come from config, not a frontend literal.
        $response->assertJsonPath('audio_constraints.echoCancellation', true);
        $response->assertJsonPath('audio_constraints.noiseSuppression', true);
        $response->assertJsonPath('audio_constraints.autoGainControl', true);
        $this->assertStringStartsWith('ek_fake_', (string) $response->json('client_secret'));
        $this->assertStringNotContainsString(self::STANDING_KEY, $response->getContent() ?: '');
        Http::assertNothingSent();
    }

    public function test_getusermedia_constraints_are_configurable(): void
    {
        config()->set('ai.realtime.audio.constraints.noiseSuppression', false);
        $this->actingAsAdmin();

        $this->postJson('/studio/live/session')
            ->assertOk()
            ->assertJsonPath('audio_constraints.noiseSuppression', false)
            ->assertJsonPath('audio_constraints.echoCancellation', true);
    }

    public function test_the_default_session_length_is_twenty_minutes(): void
    {
        // No override: the config default (config/ai.php) is the source of truth.
        $this->actingAsAdmin();

        $this->postJson('/studio/live/session')
            ->assertOk()
            ->assertJsonPath('session_max_seconds', 1200);
    }

    public function test_the_session_length_is_configurable_including_longer_rehearsals(): void
    {
        $this->actingAsAdmin();

        config()->set('ai.realtime.session_max_seconds', 2400); // 40-minute rehearsal
        $this->postJson('/studio/live/session')->assertOk()->assertJsonPath('session_max_seconds', 2400);

        config()->set('ai.realtime.session_max_seconds', 480); // shorter
        $this->postJson('/studio/live/session')->assertOk()->assertJsonPath('session_max_seconds', 480);
    }

    public function test_the_openai_driver_mints_via_the_api_and_the_standing_key_stays_server_side(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_live_studio_123',
                'expires_at' => 1_900_000_000,
                'session' => ['model' => 'gpt-realtime'],
            ]),
        ]);
        $this->useOpenAiDriver();
        $this->actingAsAdmin();

        $response = $this->postJson('/studio/live/session')->assertOk();

        $response->assertJsonPath('client_secret', 'ek_live_studio_123');
        $this->assertStringNotContainsString(self::STANDING_KEY, $response->getContent() ?: '');

        Http::assertSent(function (Request $request): bool {
            $session = $request->data()['session'];

            return $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
                && $request->hasHeader('Authorization', 'Bearer '.self::STANDING_KEY)
                && $session['instructions'] === 'Stüdyo brifingi: Türkçe tartış.'
                // no voice picked → the configured default voice for this test
                && $session['audio']['output']['voice'] === 'cedar'
                // config default turn detection is semantic_vad, wired through
                && $session['audio']['input']['turn_detection']['type'] === 'semantic_vad';
        });
    }

    public function test_the_director_can_pick_an_allow_listed_voice_for_the_session(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response(
                ['value' => 'ek_x', 'expires_at' => 1_900_000_000],
            ),
        ]);
        $this->useOpenAiDriver();
        $this->actingAsAdmin();

        $this->postJson('/studio/live/session', ['voice' => 'ash'])->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->data()['session']['audio']['output']['voice'] === 'ash');
    }

    public function test_an_unknown_requested_voice_falls_back_to_the_default(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response(
                ['value' => 'ek_x', 'expires_at' => 1_900_000_000],
            ),
        ]);
        $this->useOpenAiDriver();
        $this->actingAsAdmin();

        $this->postJson('/studio/live/session', ['voice' => 'totally-not-a-voice'])->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->data()['session']['audio']['output']['voice'] === 'cedar');
    }

    public function test_an_upstream_failure_degrades_to_a_safe_503_with_no_key(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response([
                'error' => ['message' => 'bad key '.self::STANDING_KEY, 'type' => 'invalid_request_error'],
            ], 401),
        ]);
        $this->useOpenAiDriver();
        $this->actingAsAdmin();

        $response = $this->postJson('/studio/live/session')->assertStatus(503);

        $response->assertJsonPath('error', 'realtime_unavailable');
        $this->assertStringNotContainsString(self::STANDING_KEY, $response->getContent() ?: '');
    }

    public function test_the_session_endpoint_is_rate_limited(): void
    {
        $this->actingAsAdmin();

        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/studio/live/session')->assertOk();
        }

        $this->postJson('/studio/live/session')->assertStatus(429);
    }
}
