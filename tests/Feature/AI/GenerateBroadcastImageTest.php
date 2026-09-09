<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Imaging\GenerateBroadcastImage;
use App\AI\Providers\Fake\FakeImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class GenerateBroadcastImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function service(): GenerateBroadcastImage
    {
        return $this->app->make(GenerateBroadcastImage::class);
    }

    public function test_it_asks_the_provider_with_the_assembled_prompt_and_a_valid_size(): void
    {
        $image = ($this->service())('bir pazar yeri', '1024x1024');

        $this->assertSame('1024x1024', $image->size);

        $call = $this->app->make(FakeImageProvider::class)->lastCall();
        $this->assertNotNull($call);
        $this->assertStringContainsString('OPERATÖR İSTEĞİ: bir pazar yeri', $call->prompt);
    }

    public function test_an_unknown_size_falls_back_to_the_configured_default(): void
    {
        config()->set('ai.image.size', '1536x1024');

        $image = ($this->service())('bir pazar yeri', 'not-a-real-size');

        $this->assertSame('1536x1024', $image->size);
    }

    public function test_a_null_size_uses_the_configured_default(): void
    {
        config()->set('ai.image.size', '1024x1536');

        $image = ($this->service())('bir pazar yeri');

        $this->assertSame('1024x1536', $image->size);
    }

    public function test_a_blank_brief_is_rejected_before_the_provider_is_called(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->service())('  ');
    }
}
