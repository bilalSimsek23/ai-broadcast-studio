<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Dtos\ImageGenerationRequest;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Providers\OpenAi\OpenAiImageProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiImageProviderTest extends TestCase
{
    private const API_KEY = 'openai-image-standing-key-value';

    private const BASE_URL = 'https://api.openai.test/v1';

    private const ENDPOINT = self::BASE_URL.'/images/generations';

    private function provider(string $apiKey = self::API_KEY, string $quality = 'auto'): OpenAiImageProvider
    {
        return new OpenAiImageProvider($apiKey, self::BASE_URL, 'gpt-image-1', $quality, 60, 10);
    }

    public function test_it_generates_an_image_and_maps_it_to_a_neutral_dto(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => [['b64_json' => 'aGVsbG8=']]])]);

        $image = $this->provider()->generate(new ImageGenerationRequest('bir pazar yeri', '1536x1024'));

        $this->assertSame('image/png', $image->mimeType);
        $this->assertSame('1536x1024', $image->size);
        $this->assertSame('aGVsbG8=', $image->base64);
    }

    public function test_it_sends_the_standing_key_as_a_bearer_and_the_prompt_and_size(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => [['b64_json' => 'aGVsbG8=']]])]);

        $this->provider()->generate(new ImageGenerationRequest('KONU: pazar yeri', '1024x1024'));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $body['model'] === 'gpt-image-1'
                && $body['prompt'] === 'KONU: pazar yeri'
                && $body['size'] === '1024x1024'
                && $body['n'] === 1;
        });
    }

    public function test_a_non_auto_quality_is_forwarded_but_auto_is_not(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => [['b64_json' => 'aGVsbG8=']]])]);

        $this->provider(quality: 'high')->generate(new ImageGenerationRequest('x', '1024x1024'));
        Http::assertSent(fn (Request $request): bool => ($request->data()['quality'] ?? null) === 'high');

        Http::fake([self::ENDPOINT => Http::response(['data' => [['b64_json' => 'aGVsbG8=']]])]);
        $this->provider()->generate(new ImageGenerationRequest('x', '1024x1024'));
        Http::assertSent(fn (Request $request): bool => ! array_key_exists('quality', $request->data()));
    }

    public function test_a_request_quality_overrides_the_adapter_default(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => [['b64_json' => 'aGVsbG8=']]])]);

        // adapter default 'low', request asks 'high'
        $this->provider(quality: 'low')->generate(new ImageGenerationRequest('x', '1024x1024', 'high'));

        Http::assertSent(fn (Request $request): bool => ($request->data()['quality'] ?? null) === 'high');
    }

    public function test_a_missing_standing_key_fails_before_any_network_call(): void
    {
        Http::fake();

        try {
            $this->provider(apiKey: '')->generate(new ImageGenerationRequest('x', '1024x1024'));
            $this->fail('Expected '.ProviderException::class);
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_a_connection_failure_becomes_a_timeout_exception_without_transport_detail(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: timed out to '.self::BASE_URL);
        });

        try {
            $this->provider()->generate(new ImageGenerationRequest('x', '1024x1024'));
            $this->fail('Expected '.ProviderTimeoutException::class);
        } catch (ProviderTimeoutException $e) {
            $this->assertStringNotContainsString(self::BASE_URL, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function test_a_rejected_request_carries_only_status_type_and_code_no_vendor_prose(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => [
                    'message' => 'Your prompt "sk-'.self::API_KEY.'" was rejected by the safety system.',
                    'type' => 'image_generation_user_error',
                    'code' => 'moderation_blocked',
                ],
            ], 400),
        ]);

        try {
            $this->provider()->generate(new ImageGenerationRequest('x', '1024x1024'));
            $this->fail('Expected '.ProviderRequestException::class);
        } catch (ProviderRequestException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('type: image_generation_user_error', $e->getMessage());
            $this->assertStringContainsString('code: moderation_blocked', $e->getMessage());
            $this->assertStringNotContainsString('was rejected by the safety system', $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function test_a_response_without_image_data_is_a_provider_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => []], 200)]);

        $this->expectException(ProviderException::class);

        $this->provider()->generate(new ImageGenerationRequest('x', '1024x1024'));
    }

    public function test_a_garbage_base64_payload_is_a_provider_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => [['b64_json' => 'not base64 !!!']]], 200)]);

        $this->expectException(ProviderException::class);

        $this->provider()->generate(new ImageGenerationRequest('x', '1024x1024'));
    }
}
