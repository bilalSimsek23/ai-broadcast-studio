<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Dtos\GenerationParameters;
use App\AI\Dtos\Message;
use App\AI\Dtos\MessageList;
use App\AI\Dtos\ResponseMetadata;
use App\AI\Dtos\TextGenerationRequest;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Dtos\TokenUsage;
use App\AI\Exceptions\InvalidGenerationParameters;
use App\AI\Exceptions\UnknownModelKey;
use App\AI\GenerateText;
use App\AI\Providers\Fake\FakeTextProvider;
use Tests\Support\Fakes\RecordingTextProvider;
use Tests\TestCase;

class GenerateTextTest extends TestCase
{
    private function generate(TextGenerationRequest $request): TextGenerationResponse
    {
        return $this->app->make(GenerateText::class)->generate($request);
    }

    private function fake(): FakeTextProvider
    {
        return $this->app->make(FakeTextProvider::class);
    }

    private function request(string $model, ?GenerationParameters $parameters = null): TextGenerationRequest
    {
        return new TextGenerationRequest(
            model: $model,
            messages: new MessageList(
                Message::user('İslam öncesi Arap toplumunda kadının konumu neydi?'),
                Message::assistant('Kısa bir bağlam vereyim.'),
                Message::user('Peki miras hakları?'),
            ),
            systemInstructions: 'Sen Hikmet adlı tarih araştırmacısısın.',
            parameters: $parameters,
        );
    }

    public function test_it_routes_to_the_provider_bound_to_the_requested_logical_model(): void
    {
        // A second, distinct provider implementation, bound to its own model key.
        $this->app->singleton(RecordingTextProvider::class);
        config()->set('ai.text.drivers.recording', RecordingTextProvider::class);
        config()->set('ai.text.providers.analyst', ['driver' => 'recording']);
        config()->set('ai.text.models.analyst_deepdive', [
            'provider' => 'analyst',
            'model' => 'recording-model-v1',
            'parameters' => ['temperature' => 0.3],
        ]);

        $response = $this->generate($this->request('analyst_deepdive'));

        // The call landed on the recording provider, not the default fake.
        $recording = $this->app->make(RecordingTextProvider::class);
        $this->assertCount(1, $recording->calls);
        $this->assertFalse($this->fake()->wasCalled());
        $this->assertSame('routed-to-recording-provider', $response->text);
        $this->assertSame('analyst', $response->metadata->logicalProvider);
        $this->assertSame('recording-model-v1', $recording->calls[0]->vendorModelId);
    }

    public function test_it_resolves_provider_and_vendor_model_id_from_the_logical_model(): void
    {
        $this->generate($this->request('small'));

        $call = $this->fake()->lastCall();
        $this->assertSame('fake-small-v1', $call?->vendorModelId);
        $this->assertSame('fast', $call?->logicalProvider);
        $this->assertSame('small', $call?->logicalModel);
    }

    public function test_system_instructions_and_messages_reach_the_provider_unchanged(): void
    {
        $request = $this->request('default');

        $this->generate($request);

        $call = $this->fake()->lastCall();
        $this->assertSame('Sen Hikmet adlı tarih araştırmacısısın.', $call?->systemInstructions);
        $this->assertSame(
            $request->messages->toArray(),
            $call?->messages->toArray(),
        );
    }

    public function test_configured_default_parameters_are_applied_when_the_caller_overrides_nothing(): void
    {
        $this->generate($this->request('default'));

        $params = $this->fake()->lastCall()?->parameters;
        $this->assertSame(0.7, $params?->temperature);
        $this->assertSame(800, $params?->maxOutputTokens);
    }

    public function test_a_caller_override_beats_the_configured_default_field_by_field(): void
    {
        $this->generate($this->request('default', new GenerationParameters(temperature: 0.1)));

        $params = $this->fake()->lastCall()?->parameters;
        // documented precedence: request override wins...
        $this->assertSame(0.1, $params?->temperature);
        // ...and the untouched field still comes from the logical-model default.
        $this->assertSame(800, $params?->maxOutputTokens);
    }

    public function test_an_out_of_range_override_is_rejected_before_any_provider_call(): void
    {
        try {
            $this->request('default', new GenerationParameters(temperature: 9.9));
            $this->fail('Expected '.InvalidGenerationParameters::class);
        } catch (InvalidGenerationParameters) {
            $this->assertFalse($this->fake()->wasCalled());
        }
    }

    public function test_an_unknown_logical_model_fails_without_calling_a_provider(): void
    {
        try {
            $this->generate($this->request('does-not-exist'));
            $this->fail('Expected '.UnknownModelKey::class);
        } catch (UnknownModelKey) {
            $this->assertFalse($this->fake()->wasCalled());
        }
    }

    public function test_it_returns_the_providers_neutral_response_unchanged(): void
    {
        $scripted = new TextGenerationResponse(
            text: 'Bu bir test cevabıdır.',
            metadata: new ResponseMetadata(
                logicalProvider: 'default',
                logicalModel: 'default',
                providerModelId: 'fake-balanced-v1',
            ),
            usage: new TokenUsage(inputTokens: 42, outputTokens: 17),
        );
        $this->fake()->queueResponse($scripted);

        $response = $this->generate($this->request('default'));

        $this->assertSame($scripted, $response);
        $this->assertSame('Bu bir test cevabıdır.', $response->text);
    }

    public function test_the_usage_on_the_response_is_vendor_neutral(): void
    {
        $this->fake()->reportUsage(new TokenUsage(inputTokens: 10, outputTokens: 5));

        $usage = $this->generate($this->request('default'))->usage;

        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertSame(15, $usage->totalTokens());
        $this->assertSame(['input_tokens', 'output_tokens', 'total_tokens'], array_keys($usage->toArray()));
    }

    public function test_the_fake_provider_path_needs_no_credentials(): void
    {
        // The fake driver has no credential/connection config, and the
        // resolve -> generate path never reads one. (This must not inspect any
        // vendor env var — a real key may legitimately be set for other tooling
        // and must not surface in test output.)
        $this->assertSame([], config('ai.text.connections.fake'));

        $response = $this->generate($this->request('default'));

        $this->assertNotSame('', $response->text);
        $this->assertSame('fake-balanced-v1', $this->fake()->lastCall()?->vendorModelId);
    }
}
