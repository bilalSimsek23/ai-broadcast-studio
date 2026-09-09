<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\AI\Exceptions\ProviderException;
use App\AI\Providers\Fake\FakeImageProvider;
use App\Jobs\GenerateBroadcastImageJob;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /studio/image dispatches a queued job and returns a ticket;
 * GET /studio/image/{ticket} reports the job's result. Admin-gated, throttled,
 * no credential in any response, nothing persisted beyond a short cache entry.
 */
class StudioImageEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Run the job inline and keep its result readable across the poll
        // request (the array cache would not survive a second request).
        config()->set('queue.default', 'sync');
        config()->set('cache.default', 'database');
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function generateAndPoll(array $payload): array
    {
        $ticket = $this->postJson('/studio/image', $payload)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->json('ticket');

        return (array) $this->getJson('/studio/image/'.$ticket)->assertOk()->json();
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

    public function test_the_post_dispatches_a_job_with_the_operator_choices(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $this->postJson('/studio/image', [
            'prompt' => 'bir pazar yeri, gün batımı',
            'size' => '1024x1536',
            'quality' => 'high',
        ])->assertStatus(202)->assertJsonStructure(['ticket', 'status']);

        Queue::assertPushed(GenerateBroadcastImageJob::class, function (GenerateBroadcastImageJob $job): bool {
            return $job->prompt === 'bir pazar yeri, gün batımı'
                && $job->size === '1024x1536'
                && $job->quality === 'high';
        });
    }

    public function test_the_full_flow_yields_a_data_uri_and_leaks_no_credential(): void
    {
        config()->set('ai.image.connections.openai.api_key', 'CANARY-image-key-must-not-leak');
        $this->actingAsAdmin();

        $result = $this->generateAndPoll(['prompt' => 'bir pazar yeri, gün batımı']);

        $this->assertSame('ready', $result['status']);
        $this->assertStringStartsWith('data:image/', (string) $result['image']);
        $this->assertStringNotContainsString('CANARY-image-key-must-not-leak', json_encode($result) ?: '');
        $this->assertStringNotContainsString('sk-', (string) $result['image']);
        Http::assertNothingSent();
    }

    public function test_it_uses_the_selected_episode_as_prompt_context(): void
    {
        $this->actingAsAdmin();
        $episode = Episode::factory()->create(['title' => 'Kadının Toplumdaki Yeri', 'main_topic' => 'Cahiliye dönemi']);
        $episode->show()->update(['name' => 'Gerçeğin Peşinde']);

        $this->generateAndPoll(['prompt' => 'bir pazar yeri', 'episode' => $episode->uuid]);

        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertStringContainsString('Gerçeğin Peşinde', $call->prompt);
        $this->assertStringContainsString('Cahiliye dönemi', $call->prompt);
    }

    public function test_a_size_and_quality_are_validated_against_the_allow_lists(): void
    {
        $this->actingAsAdmin();

        $this->generateAndPoll(['prompt' => 'bir pazar yeri', 'size' => '1024x1536', 'quality' => 'medium']);
        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertSame('1024x1536', $call->size);
        $this->assertSame('medium', $call->quality);

        $this->generateAndPoll(['prompt' => 'bir pazar yeri', 'size' => 'wat', 'quality' => 'ultra']);
        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertSame(config('ai.image.size'), $call->size);
        $this->assertNull($call->quality);
    }

    public function test_an_unknown_ticket_reports_expired(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/studio/image/'.Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('status', 'expired');
    }

    public function test_a_failed_job_surfaces_as_a_safe_failed_status(): void
    {
        $this->actingAsAdmin();

        $ticket = (string) Str::uuid();
        Cache::put(GenerateBroadcastImageJob::cacheKey($ticket), ['status' => 'pending'], 600);
        (new GenerateBroadcastImageJob($ticket, 'bir pazar yeri', null, null, null))->failed(
            new ProviderException('bad key CANARY-image-key-must-not-leak'),
        );

        $response = $this->getJson('/studio/image/'.$ticket)->assertOk();
        $response->assertJsonPath('status', 'failed');
        $this->assertStringNotContainsString('CANARY-image-key-must-not-leak', $response->getContent() ?: '');
    }

    public function test_the_generate_endpoint_is_rate_limited(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertStatus(202);
        }

        $this->postJson('/studio/image', ['prompt' => 'bir pazar yeri'])->assertStatus(429);
    }
}
