<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAi;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\ResolvedTextGenerationRequest;
use App\AI\Dtos\ResponseMetadata;
use App\AI\Dtos\TextGenerationResponse;
use App\AI\Dtos\TokenUsage;
use App\AI\Enums\FinishReason;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The real OpenAI text-generation adapter — the ONLY place OpenAI's HTTP shape
 * (Chat Completions) is known. It receives a fully-resolved, vendor-neutral
 * request and returns a vendor-neutral response; every OpenAI-specific detail
 * (endpoint, payload keys, `choices`/`usage`/`finish_reason` layout, error
 * envelope) stays inside this class.
 *
 * Contract with the rest of the app:
 *  - credentials come only from config('ai.text.connections.openai'), which
 *    reads them from the environment — never a constructor literal, never a
 *    persisted value;
 *  - the API key is never logged, echoed, or placed in an exception;
 *  - every call is time-bounded (connect + read timeout);
 *  - transport / HTTP / parse failures are translated into
 *    {@see ProviderException} and its subtypes — a raw Guzzle/Laravel HTTP
 *    exception or raw vendor body never escapes this method;
 *  - no streaming (deferred).
 */
final class OpenAiTextProvider implements TextGenerationProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
    ) {}

    public function generate(ResolvedTextGenerationRequest $request): TextGenerationResponse
    {
        if (trim($this->apiKey) === '') {
            throw ProviderException::missingCredentials();
        }

        $response = $this->send($this->payload($request));

        if ($response->failed()) {
            throw ProviderRequestException::fromResponse(
                status: $response->status(),
                type: $this->shortErrorField($response, 'type'),
                code: $this->shortErrorField($response, 'code'),
            );
        }

        return $this->toNeutralResponse($request, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        try {
            return Http::asJson()
                ->withToken($this->apiKey)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->retry(2, 250, throw: false)
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException) {
            // Connection refused / DNS / TLS / read timeout — never leak the
            // underlying message (it can contain the resolved host/URL).
            throw ProviderTimeoutException::afterSeconds($this->timeoutSeconds);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ResolvedTextGenerationRequest $request): array
    {
        $payload = [
            'model' => $request->vendorModelId,
            'messages' => $this->messages($request),
        ];

        if ($request->parameters->temperature !== null) {
            $payload['temperature'] = $request->parameters->temperature;
        }

        if ($request->parameters->maxOutputTokens !== null) {
            $payload['max_completion_tokens'] = $request->parameters->maxOutputTokens;
        }

        return $payload;
    }

    /**
     * @return non-empty-list<array{role: string, content: string}>
     */
    private function messages(ResolvedTextGenerationRequest $request): array
    {
        $messages = [];

        if ($request->systemInstructions !== null) {
            $messages[] = ['role' => 'system', 'content' => $request->systemInstructions];
        }

        foreach ($request->messages->all() as $message) {
            $messages[] = ['role' => $message->role->value, 'content' => $message->content];
        }

        return $messages;
    }

    private function toNeutralResponse(ResolvedTextGenerationRequest $request, Response $response): TextGenerationResponse
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw ProviderException::malformedResponse();
        }

        $choices = $body['choices'] ?? null;
        $choice = is_array($choices) && isset($choices[0]) && is_array($choices[0]) ? $choices[0] : null;

        if ($choice === null) {
            throw ProviderException::malformedResponse();
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = $message['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw ProviderException::malformedResponse();
        }

        $providerModelId = is_string($body['model'] ?? null) && ($body['model'] !== '')
            ? $body['model']
            : $request->vendorModelId;

        return new TextGenerationResponse(
            text: $content,
            metadata: new ResponseMetadata(
                logicalProvider: $request->logicalProvider,
                logicalModel: $request->logicalModel,
                providerModelId: $providerModelId,
                finishReason: $this->finishReason(is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null),
            ),
            usage: $this->usage(is_array($body['usage'] ?? null) ? $body['usage'] : []),
        );
    }

    /**
     * @param  array<array-key, mixed>  $usage
     */
    private function usage(array $usage): TokenUsage
    {
        return new TokenUsage(
            inputTokens: $this->nonNegativeInt($usage['prompt_tokens'] ?? null),
            outputTokens: $this->nonNegativeInt($usage['completion_tokens'] ?? null),
        );
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function finishReason(?string $raw): FinishReason
    {
        return match ($raw) {
            'stop' => FinishReason::Stop,
            'length', 'max_tokens' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    /**
     * Pull a SHORT, enum-like field out of the vendor error envelope
     * (`{"error": {"type": "...", "code": "..."}}`). Anything that is not a
     * compact token (i.e. a free-text sentence) is dropped, so no vendor prose
     * — which can quote request content — reaches a log or the operator.
     */
    private function shortErrorField(Response $response, string $key): ?string
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $value = $error[$key] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $value) === 1
            ? $value
            : null;
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }
}
