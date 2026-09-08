<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Dtos\RealtimeSessionRequest;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Providers\OpenAi\OpenAiRealtimeProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiRealtimeProviderTest extends TestCase
{
    private const API_KEY = 'openai-realtime-standing-key-value';

    private const BASE_URL = 'https://api.openai.test/v1';

    private const ENDPOINT = self::BASE_URL.'/realtime/client_secrets';

    private function provider(string $apiKey = self::API_KEY): OpenAiRealtimeProvider
    {
        return new OpenAiRealtimeProvider($apiKey, self::BASE_URL, 'gpt-realtime', 'marin', 15, 10);
    }

    private function request(?string $voiceOverride = null): RealtimeSessionRequest
    {
        return new RealtimeSessionRequest('Türkçe konuş ve kısa cevap ver.', $voiceOverride);
    }

    public function test_it_mints_an_ephemeral_session_and_maps_it_to_a_neutral_token(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'value' => 'ek_live_abc123',
                'expires_at' => 1_900_000_000,
                'session' => ['model' => 'gpt-realtime-2026'],
            ]),
        ]);

        $token = $this->provider()->createClientSession($this->request());

        $this->assertSame('ek_live_abc123', $token->clientSecret);
        $this->assertSame(1_900_000_000, $token->expiresAt);
        $this->assertSame('gpt-realtime-2026', $token->model);
        $this->assertSame('marin', $token->voice);
    }

    public function test_it_sends_the_standing_key_as_a_bearer_and_a_realtime_session_body(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        $this->provider()->createClientSession($this->request());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $body['session']['type'] === 'realtime'
                && $body['session']['model'] === 'gpt-realtime'
                && $body['session']['instructions'] === 'Türkçe konuş ve kısa cevap ver.'
                && $body['session']['audio']['output']['voice'] === 'marin';
        });
    }

    public function test_a_voice_override_is_applied_to_the_payload_and_the_token(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        $token = $this->provider()->createClientSession($this->request(voiceOverride: 'cedar'));

        $this->assertSame('cedar', $token->voice);
        Http::assertSent(fn (Request $request): bool => $request->data()['session']['audio']['output']['voice'] === 'cedar');
    }

    public function test_it_accepts_the_nested_client_secret_shape(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'client_secret' => ['value' => 'ek_nested', 'expires_at' => 1_900_000_000],
            ]),
        ]);

        $token = $this->provider()->createClientSession($this->request());

        $this->assertSame('ek_nested', $token->clientSecret);
    }

    public function test_a_missing_standing_key_fails_before_any_network_call(): void
    {
        Http::fake();

        try {
            $this->provider(apiKey: '')->createClientSession($this->request());
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
            $this->provider()->createClientSession($this->request());
            $this->fail('Expected '.ProviderTimeoutException::class);
        } catch (ProviderTimeoutException $e) {
            $this->assertStringNotContainsString(self::BASE_URL, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function test_a_rejected_mint_carries_only_status_type_and_code_no_vendor_free_text(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => [
                    'message' => 'Invalid API key sk-'.self::API_KEY.' provided in your request.',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);

        try {
            $this->provider()->createClientSession($this->request());
            $this->fail('Expected '.ProviderRequestException::class);
        } catch (ProviderRequestException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('type: invalid_request_error', $e->getMessage());
            $this->assertStringContainsString('code: invalid_api_key', $e->getMessage());
            $this->assertStringNotContainsString('Invalid API key', $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function test_a_success_response_with_no_secret_value_is_a_provider_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['session' => ['model' => 'gpt-realtime']], 200)]);

        $this->expectException(ProviderException::class);

        $this->provider()->createClientSession($this->request());
    }

    public function test_a_standing_key_leaking_through_as_the_secret_is_rejected_not_forwarded(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['value' => 'sk-'.self::API_KEY, 'expires_at' => 1_900_000_000]),
        ]);

        $this->expectException(ProviderException::class);

        $this->provider()->createClientSession($this->request());
    }
}
