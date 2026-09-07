<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Dtos\GenerationParameters;
use App\AI\Dtos\Message;
use App\AI\Dtos\MessageList;
use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Enums\FinishReason;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Providers\OpenAi\OpenAiTextProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTextProviderTest extends TestCase
{
    private const API_KEY = 'openai-unit-test-key-value';

    private const BASE_URL = 'https://api.openai.test/v1';

    private function provider(string $apiKey = self::API_KEY): OpenAiTextProvider
    {
        return new OpenAiTextProvider($apiKey, self::BASE_URL, 30, 10);
    }

    private function request(?GenerationParameters $parameters = null): ResolvedTextGenerationRequest
    {
        return new ResolvedTextGenerationRequest(
            vendorModelId: 'gpt-4o-mini',
            logicalProvider: 'default',
            logicalModel: 'default',
            messages: new MessageList(
                Message::user('İslam öncesi Arap toplumunda kadının konumu neydi?'),
            ),
            parameters: $parameters ?? new GenerationParameters(temperature: 0.5, maxOutputTokens: 256),
            systemInstructions: 'Sen Hikmet adlı tarih araştırmacısısın.',
        );
    }

    private function fakeSuccess(string $text = 'Kısa bir bağlam vereyim.'): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini-2024-07-18',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 17],
            ]),
        ]);
    }

    public function test_it_translates_a_successful_completion_into_a_neutral_response(): void
    {
        $this->fakeSuccess('İslam öncesi dönemde kadının konumu bölgeye göre değişiyordu.');

        $response = $this->provider()->generate($this->request());

        $this->assertSame('İslam öncesi dönemde kadının konumu bölgeye göre değişiyordu.', $response->text);
        $this->assertSame('default', $response->metadata->logicalProvider);
        $this->assertSame('default', $response->metadata->logicalModel);
        $this->assertSame('gpt-4o-mini-2024-07-18', $response->metadata->providerModelId);
        $this->assertSame(FinishReason::Stop, $response->metadata->finishReason);
        $this->assertSame(42, $response->usage->inputTokens);
        $this->assertSame(17, $response->usage->outputTokens);
        $this->assertSame(59, $response->usage->totalTokens());
    }

    public function test_it_sends_a_bearer_token_and_a_well_formed_chat_completions_payload(): void
    {
        $this->fakeSuccess();

        $this->provider()->generate($this->request(new GenerationParameters(temperature: 0.3, maxOutputTokens: 128)));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::BASE_URL.'/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $body['model'] === 'gpt-4o-mini'
                && $body['temperature'] === 0.3
                && $body['max_completion_tokens'] === 128
                && $body['messages'][0] === ['role' => 'system', 'content' => 'Sen Hikmet adlı tarih araştırmacısısın.']
                && $body['messages'][1] === ['role' => 'user', 'content' => 'İslam öncesi Arap toplumunda kadının konumu neydi?'];
        });
    }

    public function test_it_omits_parameters_that_were_not_set(): void
    {
        $this->fakeSuccess();

        $this->provider()->generate($this->request(GenerationParameters::none()));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    public function test_a_missing_api_key_fails_before_any_network_call(): void
    {
        Http::fake();

        try {
            $this->provider(apiKey: '')->generate($this->request());
            $this->fail('Expected '.ProviderException::class);
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_a_connection_failure_becomes_a_timeout_exception_without_leaking_transport_detail(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms to '.self::BASE_URL);
        });

        try {
            $this->provider()->generate($this->request());
            $this->fail('Expected '.ProviderTimeoutException::class);
        } catch (ProviderTimeoutException $e) {
            $this->assertStringNotContainsString(self::BASE_URL, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringContainsString('30 seconds', $e->getMessage());
        }
    }

    public function test_a_rejected_request_becomes_a_request_exception_carrying_only_status_type_and_code(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Incorrect API key provided: openai-unit-test-key-value. You can find your key at ...',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);

        try {
            $this->provider()->generate($this->request());
            $this->fail('Expected '.ProviderRequestException::class);
        } catch (ProviderRequestException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('invalid_request_error', $e->getMessage());
            $this->assertStringContainsString('invalid_api_key', $e->getMessage());
            // The vendor's free-text message (which here even quotes the key) is dropped.
            $this->assertStringNotContainsString('Incorrect API key', $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function test_a_free_text_error_type_is_not_reflected_into_the_exception_message(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'error' => [
                    'type' => 'a long sentence masquerading as a type with secret '.self::API_KEY,
                ],
            ], 400),
        ]);

        try {
            $this->provider()->generate($this->request());
            $this->fail('Expected '.ProviderRequestException::class);
        } catch (ProviderRequestException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringNotContainsString('masquerading', $e->getMessage());
        }
    }

    public function test_a_success_status_with_an_unintelligible_body_is_a_provider_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response(['choices' => []], 200),
        ]);

        $this->expectException(ProviderException::class);

        $this->provider()->generate($this->request());
    }

    public function test_it_maps_a_length_finish_reason(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'kesildi'],
                    'finish_reason' => 'length',
                ]],
            ]),
        ]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame(FinishReason::Length, $response->metadata->finishReason);
        $this->assertNull($response->usage->inputTokens);
    }
}
