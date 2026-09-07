<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\GenerationParameters;
use App\AI\Dtos\Message;
use App\AI\Dtos\MessageList;
use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\ResponseMetadata;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Dtos\TokenUsage;
use App\AI\Enums\FinishReason;
use App\AI\Providers\Fake\FakeTextProvider;
use Tests\TestCase;

class FakeTextProviderTest extends TestCase
{
    private function request(string $lastMessage = 'son mesaj'): ResolvedTextGenerationRequest
    {
        return new ResolvedTextGenerationRequest(
            vendorModelId: 'fake-balanced-v1',
            logicalProvider: 'default',
            logicalModel: 'default',
            messages: new MessageList(Message::user($lastMessage)),
            parameters: GenerationParameters::none(),
            systemInstructions: 'sistem',
        );
    }

    public function test_it_implements_the_production_contract(): void
    {
        $this->assertInstanceOf(TextGenerationProvider::class, new FakeTextProvider);
    }

    public function test_the_default_response_is_deterministic_and_derived_only_from_the_request(): void
    {
        $fake = new FakeTextProvider;

        $a = $fake->generate($this->request('aynı girdi'));
        $b = (new FakeTextProvider)->generate($this->request('aynı girdi'));

        $this->assertSame($a->text, $b->text);
        $this->assertSame('[fake:fake-balanced-v1] aynı girdi', $a->text);
        $this->assertSame(FinishReason::Stop, $a->metadata->finishReason);
    }

    public function test_it_records_every_call_for_assertions(): void
    {
        $fake = new FakeTextProvider;
        $this->assertFalse($fake->wasCalled());

        $fake->generate($this->request('bir'));
        $fake->generate($this->request('iki'));

        $this->assertTrue($fake->wasCalled());
        $this->assertSame(2, $fake->callCount());
        $this->assertSame('iki', $fake->lastCall()?->messages->last()->content);
    }

    public function test_scripted_text_usage_and_finish_reason_are_returned(): void
    {
        $fake = (new FakeTextProvider)
            ->respondWith('sabit cevap')
            ->reportUsage(new TokenUsage(inputTokens: 3, outputTokens: 4))
            ->reportFinishReason(FinishReason::Length);

        $response = $fake->generate($this->request());

        $this->assertSame('sabit cevap', $response->text);
        $this->assertSame(3, $response->usage->inputTokens);
        $this->assertSame(7, $response->usage->totalTokens());
        $this->assertSame(FinishReason::Length, $response->metadata->finishReason);
    }

    public function test_queued_responses_are_returned_first_in_first_out(): void
    {
        $fake = new FakeTextProvider;
        $queued = new TextGenerationResponse(
            text: 'kuyruktan',
            metadata: new ResponseMetadata('default', 'default'),
            usage: TokenUsage::unknown(),
        );
        $fake->queueResponse($queued);

        $this->assertSame($queued, $fake->generate($this->request()));
        // Queue exhausted -> back to the deterministic default.
        $this->assertStringStartsWith('[fake:', $fake->generate($this->request())->text);
    }
}
