<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\AI\Exceptions\ProviderException;
use App\AI\Imaging\GenerateBroadcastImage;
use App\AI\Providers\Fake\FakeImageProvider;
use App\Jobs\GenerateBroadcastImageJob;
use App\Models\Episode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The background job that actually generates the broadcast image and writes the
 * result under the ticket key for the poller.
 */
class StudioImageJobTest extends TestCase
{
    use RefreshDatabase;

    private function job(string $ticket = 'abc', ?string $episodeUuid = null): GenerateBroadcastImageJob
    {
        return new GenerateBroadcastImageJob($ticket, 'bir pazar yeri, gün batımı', '1024x1024', 'medium', $episodeUuid);
    }

    private function service(): GenerateBroadcastImage
    {
        return $this->app->make(GenerateBroadcastImage::class);
    }

    public function test_it_stores_a_ready_result_with_a_data_uri(): void
    {
        $this->job()->handle($this->service());

        $state = Cache::get('studio:image:abc');

        $this->assertIsArray($state);
        $this->assertSame('ready', $state['status']);
        $this->assertStringStartsWith('data:image/', (string) $state['image']);
        $this->assertArrayHasKey('size', $state);
    }

    public function test_it_passes_the_quality_and_size_through_to_the_provider(): void
    {
        $this->job()->handle($this->service());

        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertSame('1024x1024', $call->size);
        $this->assertSame('medium', $call->quality);
    }

    public function test_it_folds_the_episode_context_into_the_prompt(): void
    {
        $episode = Episode::factory()->create(['title' => 'Kadının Yeri', 'main_topic' => 'Cahiliye dönemi']);
        $episode->show()->update(['name' => 'Gerçeğin Peşinde']);

        $this->job(episodeUuid: $episode->uuid)->handle($this->service());

        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertStringContainsString('Gerçeğin Peşinde', $call->prompt);
        $this->assertStringContainsString('Cahiliye dönemi', $call->prompt);
    }

    public function test_a_failure_stores_a_safe_failed_result(): void
    {
        $this->job()->failed(new ProviderException('boom CANARY'));

        $state = Cache::get('studio:image:abc');

        $this->assertIsArray($state);
        $this->assertSame('failed', $state['status']);
        $this->assertArrayHasKey('message', $state);
        $this->assertStringNotContainsString('CANARY', (string) $state['message']);
        $this->assertArrayNotHasKey('image', $state);
    }

    public function test_the_job_is_not_retried(): void
    {
        $this->assertSame(1, $this->job()->tries);
    }
}
