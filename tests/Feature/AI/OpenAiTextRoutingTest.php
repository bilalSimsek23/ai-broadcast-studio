<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Dtos\Message;
use App\AI\Dtos\MessageList;
use App\AI\Dtos\TextGenerationRequest;
use App\AI\GenerateText;
use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Providers\OpenAi\OpenAiTextProvider;
use App\AI\Resolution\LogicalModelResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proves the TASK-0005 logical routing still holds once a real driver exists:
 * a logical model key backed by the `openai` driver reaches the OpenAI adapter
 * (and its HTTP call), while the fake driver stays the default when the
 * environment does not opt in.
 */
class OpenAiTextRoutingTest extends TestCase
{
    private function routeLogicalDefaultThroughOpenAi(): void
    {
        config()->set('ai.text.providers.default.driver', 'openai');
        config()->set('ai.text.models.default.model', 'gpt-5.6-sol');
        config()->set('ai.text.connections.openai', [
            'api_key' => 'routing-test-key-value',
            'base_url' => 'https://api.openai.test/v1',
            'timeout' => 30,
            'connect_timeout' => 10,
            'send_sampling_params' => false,
        ]);

        // Both singletons read config lazily at first resolution; drop any that
        // a previous line already built so they pick up the routing config.
        $this->app->forgetInstance(LogicalModelResolver::class);
        $this->app->forgetInstance(OpenAiTextProvider::class);
    }

    private function request(string $model = 'default'): TextGenerationRequest
    {
        return new TextGenerationRequest(
            model: $model,
            messages: new MessageList(Message::user('Soru?')),
            systemInstructions: 'Sistem yönergesi.',
        );
    }

    public function test_a_logical_model_bound_to_the_openai_driver_calls_the_openai_responses_endpoint(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'model' => 'gpt-5.6-sol',
                'status' => 'completed',
                'output' => [
                    ['type' => 'message', 'role' => 'assistant', 'content' => [
                        ['type' => 'output_text', 'text' => 'Yanıt.'],
                    ]],
                ],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ]),
        ]);
        $this->routeLogicalDefaultThroughOpenAi();

        $response = $this->app->make(GenerateText::class)->generate($this->request());

        $this->assertSame('Yanıt.', $response->text);
        $this->assertSame('default', $response->metadata->logicalProvider);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.test/v1/responses'
            && $request->data()['model'] === 'gpt-5.6-sol'
            // the logical `default` model's configured temperature (0.7) is not forwarded
            && ! array_key_exists('temperature', $request->data()));
    }

    public function test_without_opting_in_the_default_logical_provider_still_uses_the_fake_driver(): void
    {
        Http::fake();

        $this->app->make(GenerateText::class)->generate($this->request());

        $this->assertTrue($this->app->make(FakeTextProvider::class)->wasCalled());
        Http::assertNothingSent();
    }
}
