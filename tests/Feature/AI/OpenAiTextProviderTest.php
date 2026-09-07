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

    private const ENDPOINT = self::BASE_URL.'/responses';

    private function provider(string $apiKey = self::API_KEY, bool $sendSampling = false): OpenAiTextProvider
    {
        return new OpenAiTextProvider($apiKey, self::BASE_URL, 30, 10, $sendSampling);
    }

    private function request(?GenerationParameters $parameters = null): ResolvedTextGenerationRequest
    {
        return new ResolvedTextGenerationRequest(
            vendorModelId: 'gpt-5.6-sol',
            logicalProvider: 'default',
            logicalModel: 'default',
            messages: new MessageList(
                Message::user('İslam öncesi Arap toplumunda kadının konumu neydi?'),
            ),
            // The logical `default` model ships temperature 0.7 from TASK-0005 config.
            parameters: $parameters ?? new GenerationParameters(temperature: 0.7, maxOutputTokens: 800),
            systemInstructions: 'Sen Hikmet adlı tarih araştırmacısısın.',
        );
    }

    private function fakeResponsesSuccess(string $text = 'Kısa bir bağlam vereyim.'): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'model' => 'gpt-5.6-sol-2026-01-01',
                'status' => 'completed',
                'output' => [
                    ['type' => 'reasoning', 'summary' => []],
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'output_text', 'text' => $text, 'annotations' => []],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 42, 'output_tokens' => 17, 'total_tokens' => 59],
            ]),
        ]);
    }

    public function test_it_calls_the_responses_endpoint_with_input_instructions_and_max_output_tokens(): void
    {
        $this->fakeResponsesSuccess();

        $this->provider()->generate($this->request(new GenerationParameters(temperature: 0.3, maxOutputTokens: 128)));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $body['model'] === 'gpt-5.6-sol'
                && $body['instructions'] === 'Sen Hikmet adlı tarih araştırmacısısın.'
                && $body['input'] === [['role' => 'user', 'content' => 'İslam öncesi Arap toplumunda kadının konumu neydi?']]
                && $body['max_output_tokens'] === 128
                && ! array_key_exists('messages', $body)
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    /**
     * Regression: the production `HTTP 400 · invalid_request_error ·
     * unsupported_value` was `temperature: 0.7` (inherited from the TASK-0005
     * logical-model config) being sent to a GPT-5.x model, which only accepts
     * the default temperature. The adapter must NOT forward temperature by
     * default, even though the resolved request still carries it.
     */
    public function test_it_never_sends_temperature_by_default_even_when_the_request_carries_one(): void
    {
        $this->fakeResponsesSuccess();

        $this->provider()->generate($this->request(new GenerationParameters(temperature: 0.7, maxOutputTokens: 800)));

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('temperature', $request->data())
            && ! array_key_exists('top_p', $request->data()));
    }

    public function test_it_forwards_temperature_only_when_the_connection_opts_in(): void
    {
        $this->fakeResponsesSuccess();

        $this->provider(sendSampling: true)
            ->generate($this->request(new GenerationParameters(temperature: 0.3, maxOutputTokens: 128)));

        Http::assertSent(fn (Request $request): bool => $request->data()['temperature'] === 0.3);
    }

    public function test_it_omits_max_output_tokens_when_it_was_not_set(): void
    {
        $this->fakeResponsesSuccess();

        $this->provider()->generate($this->request(GenerationParameters::none()));

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('max_output_tokens', $request->data())
            && ! array_key_exists('temperature', $request->data()));
    }

    public function test_it_translates_a_completed_response_into_a_neutral_response(): void
    {
        $this->fakeResponsesSuccess('İslam öncesi dönemde kadının konumu bölgeye göre değişiyordu.');

        $response = $this->provider()->generate($this->request());

        $this->assertSame('İslam öncesi dönemde kadının konumu bölgeye göre değişiyordu.', $response->text);
        $this->assertSame('default', $response->metadata->logicalProvider);
        $this->assertSame('default', $response->metadata->logicalModel);
        $this->assertSame('gpt-5.6-sol-2026-01-01', $response->metadata->providerModelId);
        $this->assertSame(FinishReason::Stop, $response->metadata->finishReason);
        $this->assertSame(42, $response->usage->inputTokens);
        $this->assertSame(17, $response->usage->outputTokens);
        $this->assertSame(59, $response->usage->totalTokens());
    }

    public function test_it_aggregates_multiple_output_text_parts_and_skips_non_text_items(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'status' => 'completed',
                'output' => [
                    ['type' => 'reasoning', 'summary' => []],
                    ['type' => 'message', 'role' => 'assistant', 'content' => [
                        ['type' => 'output_text', 'text' => 'Birinci kısım. '],
                        ['type' => 'output_text', 'text' => 'İkinci kısım.'],
                    ]],
                ],
            ]),
        ]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame('Birinci kısım. İkinci kısım.', $response->text);
        $this->assertNull($response->usage->inputTokens);
    }

    public function test_an_incomplete_response_capped_by_tokens_maps_to_the_length_finish_reason(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [
                    ['type' => 'message', 'role' => 'assistant', 'content' => [
                        ['type' => 'output_text', 'text' => 'kesildi'],
                    ]],
                ],
            ]),
        ]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame(FinishReason::Length, $response->metadata->finishReason);
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

    /**
     * The exact production failure envelope for the temperature bug:
     * `{"error":{"type":"invalid_request_error","code":"unsupported_value","param":"temperature", ...}}`.
     * The exception must name status + type + code + param and NOTHING else —
     * the vendor's free-text message (which can quote request content or a key)
     * is dropped.
     */
    public function test_an_unsupported_value_rejection_carries_only_status_type_code_and_param(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => [
                    'message' => "Unsupported value: 'temperature' does not support 0.7 with this model. "
                        .'Only the default (1) value is supported. key='.self::API_KEY,
                    'type' => 'invalid_request_error',
                    'param' => 'temperature',
                    'code' => 'unsupported_value',
                ],
            ], 400),
        ]);

        try {
            $this->provider()->generate($this->request());
            $this->fail('Expected '.ProviderRequestException::class);
        } catch (ProviderRequestException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('type: invalid_request_error', $e->getMessage());
            $this->assertStringContainsString('code: unsupported_value', $e->getMessage());
            $this->assertStringContainsString('param: temperature', $e->getMessage());
            $this->assertStringNotContainsString('Unsupported value', $e->getMessage());
            $this->assertStringNotContainsString('does not support 0.7', $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function test_a_free_text_error_field_is_not_reflected_into_the_exception_message(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
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

    public function test_a_success_status_with_no_output_text_is_a_provider_exception(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['status' => 'completed', 'output' => []], 200),
        ]);

        $this->expectException(ProviderException::class);

        $this->provider()->generate($this->request());
    }
}
