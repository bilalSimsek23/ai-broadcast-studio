<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAi;

use App\AI\Contracts\ImageGenerationProvider;
use App\AI\Dtos\GeneratedImage;
use App\AI\Dtos\ImageGenerationRequest;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The real OpenAI image adapter — the ONLY place OpenAI's image HTTP shape is
 * known (`POST {base_url}/images/generations`). It receives a vendor-neutral
 * request and returns a vendor-neutral {@see GeneratedImage}; the image bytes
 * come back base64-encoded and are handed to the browser as a data: URI.
 *
 * Contract with the rest of the app:
 *  - credentials come only from config('ai.image.connections.openai'), read
 *    from the environment — never a constructor literal, never persisted;
 *  - the API key is never logged, echoed, or placed in an exception;
 *  - every call is time-bounded (connect + read timeout);
 *  - transport / HTTP / parse failures are translated into
 *    {@see ProviderException} and its subtypes — a raw HTTP exception or raw
 *    vendor body never escapes.
 *
 * Generation is synchronous and can take tens of seconds; the timeout default
 * (60s) is deliberately generous and the calling endpoint is rate-limited.
 */
final class OpenAiImageProvider implements ImageGenerationProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $quality,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
    ) {}

    public function generate(ImageGenerationRequest $request): GeneratedImage
    {
        if (trim($this->apiKey) === '') {
            throw ProviderException::missingCredentials();
        }

        $payload = [
            'model' => $this->model,
            'prompt' => $request->prompt,
            'size' => $request->size,
            'n' => 1,
        ];

        // Per-request quality (from Studio Control) wins over the configured
        // default. `auto` is the model default and is not sent on the wire.
        $quality = $request->quality ?? $this->quality;
        if (trim($quality) !== '' && $quality !== 'auto') {
            $payload['quality'] = $quality;
        }

        try {
            $response = Http::asJson()
                ->withToken($this->apiKey)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->retry(1, 500, throw: false)
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException) {
            throw ProviderTimeoutException::afterSeconds($this->timeoutSeconds);
        }

        if ($response->failed()) {
            throw ProviderRequestException::fromResponse(
                status: $response->status(),
                type: $this->shortErrorField($response, 'type'),
                code: $this->shortErrorField($response, 'code'),
                param: $this->shortErrorField($response, 'param'),
            );
        }

        return $this->toImage($response, $request->size);
    }

    private function toImage(Response $response, string $size): GeneratedImage
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw ProviderException::malformedResponse();
        }

        $data = $body['data'] ?? null;
        $first = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        $base64 = $first['b64_json'] ?? null;

        if (! is_string($base64) || trim($base64) === '') {
            throw ProviderException::malformedResponse();
        }

        try {
            // gpt-image-1 returns PNG bytes unless output_format is set (it is not).
            return new GeneratedImage('image/png', $base64, $size);
        } catch (\InvalidArgumentException) {
            throw ProviderException::malformedResponse();
        }
    }

    /**
     * Pull a SHORT, enum-like field out of the vendor error envelope. Anything
     * that is not a compact token (i.e. free-text prose, which can quote the
     * prompt) is dropped so it never reaches a log or the operator. Mirrors
     * {@see OpenAiTextProvider::shortErrorField()}.
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
        return rtrim($this->baseUrl, '/').'/images/generations';
    }
}
