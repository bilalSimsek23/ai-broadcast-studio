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
        return new OpenAiRealtimeProvider($apiKey, self::BASE_URL, 'gpt-realtime', 'cedar', 15, 10);
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
        $this->assertSame('cedar', $token->voice);
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
                && $body['session']['audio']['output']['voice'] === 'cedar';
        });
    }

    public function test_a_voice_override_is_applied_to_the_payload_and_the_token(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        // Override with a voice DIFFERENT from the provider default ('cedar').
        $token = $this->provider()->createClientSession($this->request(voiceOverride: 'marin'));

        $this->assertSame('marin', $token->voice);
        Http::assertSent(fn (Request $request): bool => $request->data()['session']['audio']['output']['voice'] === 'marin');
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

    public function test_without_configured_tuning_the_session_has_no_audio_input_block(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        $this->provider()->createClientSession($this->request());

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('input', $request->data()['session']['audio']));
    }

    public function test_configured_studio_noise_tuning_is_sent_in_the_session_audio_input(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        $provider = new OpenAiRealtimeProvider(
            self::API_KEY, self::BASE_URL, 'gpt-realtime', 'cedar', 15, 10,
            turnDetection: ['threshold' => 0.6, 'prefix_padding_ms' => 300, 'silence_duration_ms' => 500],
            noiseReduction: 'far_field',
        );

        $provider->createClientSession($this->request());

        Http::assertSent(function (Request $request): bool {
            $input = $request->data()['session']['audio']['input'];

            return $input['noise_reduction']['type'] === 'far_field'
                && $input['turn_detection']['type'] === 'server_vad'
                && $input['turn_detection']['threshold'] === 0.6
                && $input['turn_detection']['silence_duration_ms'] === 500
                && $input['turn_detection']['prefix_padding_ms'] === 300
                // barge-in stays on so the host can interrupt the AI
                && $input['turn_detection']['interrupt_response'] === true
                && $input['turn_detection']['create_response'] === true;
        });
    }

    public function test_semantic_vad_sends_eagerness_and_no_threshold_or_timeouts(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        $provider = new OpenAiRealtimeProvider(
            self::API_KEY, self::BASE_URL, 'gpt-realtime', 'marin', 15, 10,
            turnDetection: [
                'type' => 'semantic_vad',
                'eagerness' => 'low',
                // server_vad values present in config are ignored for semantic_vad
                'threshold' => 0.6,
                'silence_duration_ms' => 500,
            ],
        );

        $provider->createClientSession($this->request());

        Http::assertSent(function (Request $request): bool {
            $td = $request->data()['session']['audio']['input']['turn_detection'];

            return $td['type'] === 'semantic_vad'
                && $td['eagerness'] === 'low'
                && $td['interrupt_response'] === true
                && $td['create_response'] === true
                && ! array_key_exists('threshold', $td)
                && ! array_key_exists('silence_duration_ms', $td)
                && ! array_key_exists('prefix_padding_ms', $td);
        });
    }

    public function test_noise_reduction_off_is_not_sent(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['value' => 'ek_x', 'expires_at' => 1_900_000_000])]);

        $provider = new OpenAiRealtimeProvider(
            self::API_KEY, self::BASE_URL, 'gpt-realtime', 'cedar', 15, 10,
            noiseReduction: 'off',
        );

        $provider->createClientSession($this->request());

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('input', $request->data()['session']['audio']));
    }
}
