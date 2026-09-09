<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\ImageGenerationProvider;
use App\AI\Dtos\ImageGenerationRequest;
use App\AI\Providers\Fake\FakeImageProvider;
use PHPUnit\Framework\TestCase;

class FakeImageProviderTest extends TestCase
{
    public function test_it_implements_the_contract(): void
    {
        $this->assertInstanceOf(ImageGenerationProvider::class, new FakeImageProvider);
    }

    public function test_it_returns_a_visible_svg_placeholder_and_records_the_call(): void
    {
        $provider = new FakeImageProvider;
        $request = new ImageGenerationRequest('bir pazar yeri', '1024x1024');

        $image = $provider->generate($request);

        $this->assertSame('image/svg+xml', $image->mimeType);
        $this->assertSame('1024x1024', $image->size);

        $decoded = base64_decode($image->base64, true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
        $this->assertStringContainsString('1024x1024', $decoded);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $image->toDataUri());
        $this->assertSame($request, $provider->lastCall());
        $this->assertSame(1, $provider->callCount());
    }

    public function test_it_is_deterministic_for_the_same_request(): void
    {
        $a = (new FakeImageProvider)->generate(new ImageGenerationRequest('x', '1024x1024'));
        $b = (new FakeImageProvider)->generate(new ImageGenerationRequest('y', '1024x1024'));

        $this->assertSame($a->base64, $b->base64);
    }

    public function test_reset_clears_recorded_calls(): void
    {
        $provider = new FakeImageProvider;
        $provider->generate(new ImageGenerationRequest('x', '1024x1024'));

        $provider->reset();

        $this->assertSame(0, $provider->callCount());
        $this->assertNull($provider->lastCall());
    }
}
