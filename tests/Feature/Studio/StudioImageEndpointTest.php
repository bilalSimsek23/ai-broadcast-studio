<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\AI\Contracts\ImageGenerationProvider;
use App\AI\Providers\Fake\FakeImageProvider;
use App\AI\Providers\OpenAi\OpenAiImageProvider;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /studio/image — the operator "Görsel Oluştur" action. Admin-gated,
 * throttled, no credential in the response, nothing persisted.
 */
class StudioImageEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_a_guest_cannot_generate_an_image(): void
    {
        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertUnauthorized();
    }

    public function test_a_non_admin_cannot_generate_an_image(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertForbidden();
    }

    public function test_a_prompt_is_required_and_has_a_minimum_length(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/studio/image', [])->assertStatus(422);
        $this->postJson('/studio/image', ['prompt' => 'ab'])->assertStatus(422);
    }

    public function test_the_fake_driver_returns_a_data_uri_and_leaks_no_credential(): void
    {
        config()->set('ai.image.connections.openai.api_key', 'CANARY-image-key-must-not-leak');
        $this->actingAsAdmin();

        $response = $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri, gün batımı'])->assertOk();

        $response->assertJsonStructure(['image', 'mime_type', 'size']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $response->json('image'));
        $this->assertStringNotContainsString('CANARY-image-key-must-not-leak', $response->getContent() ?: '');
        $this->assertStringNotContainsString('sk-', $response->getContent() ?: '');
        Http::assertNothingSent();
    }

    public function test_it_uses_the_selected_episode_as_prompt_context(): void
    {
        $this->actingAsAdmin();
        $episode = Episode::factory()->create([
            'title' => 'Kadının Toplumdaki Yeri',
            'main_topic' => 'Cahiliye dönemi',
        ]);
        $episode->show()->update(['name' => 'Gerçeğin Peşinde']);

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri', 'episode' => $episode->uuid])->assertOk();

        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertStringContainsString('Gerçeğin Peşinde', $call->prompt);
        $this->assertStringContainsString('Cahiliye dönemi', $call->prompt);
    }

    public function test_an_unknown_episode_is_ignored_not_fatal(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri', 'episode' => 'no-such-uuid'])->assertOk();
    }

    public function test_a_size_can_be_chosen_from_the_allow_list(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri', 'size' => '1024x1536'])
            ->assertOk()
            ->assertJsonPath('size', '1024x1536');
    }

    public function test_an_unknown_size_falls_back_to_the_configured_default(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri', 'size' => 'wat'])
            ->assertOk()
            ->assertJsonPath('size', config('ai.image.size'));
    }

    public function test_a_provider_failure_degrades_to_a_safe_503_with_no_key(): void
    {
        config()->set('ai.image.driver', 'openai');
        config()->set('ai.image.connections.openai', [
            'api_key' => 'CANARY-image-key-must-not-leak',
            'base_url' => 'https://api.openai.test/v1',
            'model' => 'gpt-image-1',
            'quality' => 'auto',
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
        $this->app->forgetInstance(ImageGenerationProvider::class);
        $this->app->forgetInstance(OpenAiImageProvider::class);
        Http::fake([
            'https://api.openai.test/v1/images/generations' => Http::response([
                'error' => ['message' => 'bad key CANARY-image-key-must-not-leak', 'type' => 'invalid_request_error'],
            ], 401),
        ]);
        $this->actingAsAdmin();

        $response = $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertStatus(503);

        $response->assertJsonPath('error', 'image_unavailable');
        $this->assertStringNotContainsString('CANARY-image-key-must-not-leak', $response->getContent() ?: '');
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $this->actingAsAdmin();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertOk();
        }

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertStatus(429);
    }
}
