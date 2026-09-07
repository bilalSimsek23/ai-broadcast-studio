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
 * is known. It targets the **Responses API** (`POST /v1/responses`), the
 * current recommended surface for the GPT-5.x family. It receives a
 * fully-resolved, vendor-neutral request and returns a vendor-neutral
 * response; every OpenAI-specific detail (endpoint, `input`/`instructions`
 * payload, `output[]` / `usage` layout, `status` / `incomplete_details`, error
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
 *
 * Sampling parameters: GPT-5.x reasoning models reject any non-default
 * `temperature` with `HTTP 400 unsupported_value`. The neutral layer still
 * carries `temperature` (it is meaningful for other model families), but this
 * adapter only forwards it when the connection sets `send_sampling_params`
 * — off by default, which is correct for the GPT-5.x family.
 */
final class OpenAiTextProvider implements TextGenerationProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly bool $sendSamplingParameters = false,
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
                param: $this->shortErrorField($response, 'param'),
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
     * Map the neutral request onto the Responses API payload:
     *  - system instructions  -> top-level `instructions`
     *  - conversation turns    -> `input` (array of role/content items)
     *  - maxOutputTokens       -> `max_output_tokens`
     *  - temperature           -> `temperature` ONLY when the connection opts in
     *
     * @return array<string, mixed>
     */
    private function payload(ResolvedTextGenerationRequest $request): array
    {
        $payload = [
            'model' => $request->vendorModelId,
            'input' => $this->input($request),
        ];

        if ($request->systemInstructions !== null) {
            $payload['instructions'] = $request->systemInstructions;
        }

        if ($request->parameters->maxOutputTokens !== null) {
            $payload['max_output_tokens'] = $request->parameters->maxOutputTokens;
        }

        if ($this->sendSamplingParameters && $request->parameters->temperature !== null) {
            $payload['temperature'] = $request->parameters->temperature;
        }

        return $payload;
    }

    /**
     * @return non-empty-list<array{role: string, content: string}>
     */
    private function input(ResolvedTextGenerationRequest $request): array
    {
        $input = [];

        foreach ($request->messages->all() as $message) {
            $input[] = ['role' => $message->role->value, 'content' => $message->content];
        }

        return $input;
    }

    private function toNeutralResponse(ResolvedTextGenerationRequest $request, Response $response): TextGenerationResponse
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw ProviderException::malformedResponse();
        }

        $text = $this->extractOutputText($body);

        $providerModelId = is_string($body['model'] ?? null) && $body['model'] !== ''
            ? $body['model']
            : $request->vendorModelId;

        return new TextGenerationResponse(
            text: $text,
            metadata: new ResponseMetadata(
                logicalProvider: $request->logicalProvider,
                logicalModel: $request->logicalModel,
                providerModelId: $providerModelId,
                finishReason: $this->finishReason($body),
            ),
            usage: $this->usage($body),
        );
    }

    /**
     * Aggregate the assistant text from `output[] -> content[]` items of type
     * `output_text`. Non-text items (e.g. `reasoning`, `refusal`) are skipped.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function extractOutputText(array $body): string
    {
        $output = $body['output'] ?? null;

        if (! is_array($output)) {
            throw ProviderException::malformedResponse();
        }

        $chunks = [];

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $chunks[] = $part['text'];
                }
            }
        }

        $text = trim(implode('', $chunks));

        if ($text === '') {
            throw ProviderException::malformedResponse();
        }

        return $text;
    }

    /**
     * The Responses API has no per-choice `finish_reason`; derive one from the
     * overall `status` and `incomplete_details.reason`.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function finishReason(array $body): FinishReason
    {
        $incomplete = $body['incomplete_details'] ?? null;
        $reason = is_array($incomplete) && is_string($incomplete['reason'] ?? null) ? $incomplete['reason'] : null;
        $status = is_string($body['status'] ?? null) ? $body['status'] : null;

        return match (true) {
            $reason === 'max_output_tokens' => FinishReason::Length,
            $reason === 'content_filter' => FinishReason::ContentFilter,
            $status === 'completed' => FinishReason::Stop,
            default => FinishReason::Other,
        };
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function usage(array $body): TokenUsage
    {
        $usage = $body['usage'] ?? null;

        if (! is_array($usage)) {
            return TokenUsage::unknown();
        }

        return new TokenUsage(
            inputTokens: $this->nonNegativeInt($usage['input_tokens'] ?? null),
            outputTokens: $this->nonNegativeInt($usage['output_tokens'] ?? null),
        );
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * Pull a SHORT, enum-like field out of the vendor error envelope
     * (`{"error": {"type": "...", "code": "...", "param": "..."}}`). Anything
     * that is not a compact token (i.e. a free-text sentence) is dropped, so no
     * vendor prose — which can quote request content — reaches a log or the
     * operator.
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
        return rtrim($this->baseUrl, '/').'/responses';
    }
}
