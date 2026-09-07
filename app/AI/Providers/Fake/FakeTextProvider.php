<?php

declare(strict_types=1);

namespace App\AI\Providers\Fake;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\ResponseMetadata;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Dtos\TokenUsage;
use App\AI\Enums\FinishReason;

/**
 * A deterministic, network-free {@see TextGenerationProvider} for local
 * development and tests. It lives in the application namespace (not the test
 * namespace) so it can be the configured driver.
 *
 * Behaviour:
 *  - every call is recorded (see {@see calls()} / {@see lastCall()});
 *  - responses are deterministic: either the values scripted via
 *    {@see respondWith()} / {@see queueResponse()}, or a fixed echo of the
 *    last message derived only from the request (no clock, no randomness).
 */
final class FakeTextProvider implements TextGenerationProvider
{
    /** @var list<ResolvedTextGenerationRequest> */
    private array $calls = [];

    /** @var list<TextGenerationResponse> */
    private array $queued = [];

    private ?string $scriptedText = null;

    private ?TokenUsage $scriptedUsage = null;

    private FinishReason $scriptedFinishReason = FinishReason::Stop;

    public function generate(ResolvedTextGenerationRequest $request): TextGenerationResponse
    {
        $this->calls[] = $request;

        if ($this->queued !== []) {
            return array_shift($this->queued);
        }

        $lastContent = $request->messages->last()->content;

        return new TextGenerationResponse(
            text: $this->scriptedText ?? sprintf('[fake:%s] %s', $request->vendorModelId, $lastContent),
            metadata: new ResponseMetadata(
                logicalProvider: $request->logicalProvider,
                logicalModel: $request->logicalModel,
                providerModelId: $request->vendorModelId,
                finishReason: $this->scriptedFinishReason,
            ),
            usage: $this->scriptedUsage ?? new TokenUsage(
                inputTokens: $this->wordCount($request),
                outputTokens: max(1, str_word_count($lastContent)),
            ),
        );
    }

    /**
     * Fix the text every default (non-queued) response returns.
     */
    public function respondWith(string $text): self
    {
        $this->scriptedText = $text;

        return $this;
    }

    /**
     * Fix the usage every default (non-queued) response reports.
     */
    public function reportUsage(TokenUsage $usage): self
    {
        $this->scriptedUsage = $usage;

        return $this;
    }

    public function reportFinishReason(FinishReason $reason): self
    {
        $this->scriptedFinishReason = $reason;

        return $this;
    }

    /**
     * Enqueue an exact response to return (FIFO) ahead of the default
     * behaviour. Useful for asserting the response is passed straight through.
     */
    public function queueResponse(TextGenerationResponse $response): self
    {
        $this->queued[] = $response;

        return $this;
    }

    /**
     * @return list<ResolvedTextGenerationRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function wasCalled(): bool
    {
        return $this->calls !== [];
    }

    public function lastCall(): ?ResolvedTextGenerationRequest
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)];
    }

    public function reset(): self
    {
        $this->calls = [];
        $this->queued = [];
        $this->scriptedText = null;
        $this->scriptedUsage = null;
        $this->scriptedFinishReason = FinishReason::Stop;

        return $this;
    }

    private function wordCount(ResolvedTextGenerationRequest $request): int
    {
        $total = $request->systemInstructions === null ? 0 : str_word_count($request->systemInstructions);

        foreach ($request->messages->all() as $message) {
            $total += str_word_count($message->content);
        }

        return max(1, $total);
    }
}
