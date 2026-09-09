<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAi;

use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Dtos\RealtimeSessionRequest;
use App\AI\Dtos\RealtimeSessionToken;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The real OpenAI realtime-voice adapter — the ONLY place OpenAI's realtime
 * HTTP shape is known. It mints a short-lived EPHEMERAL client secret
 * (`POST {base_url}/realtime/client_secrets`) from the standing API key; the
 * browser then runs the audio stream itself over WebRTC with that ephemeral
 * secret. The standing key never leaves this process.
 *
 * Contract with the rest of the app:
 *  - credentials come only from config('ai.realtime.connections.openai');
 *  - the standing API key is never logged, echoed, or placed in an exception,
 *    and never appears in the returned {@see RealtimeSessionToken};
 *  - the mint call is time-bounded (connect + read timeout);
 *  - transport / HTTP / parse failures are translated into
 *    {@see ProviderException} and its subtypes — a raw HTTP exception or raw
 *    vendor body never escapes.
 *
 * Studio-room noise: the input pipeline (`session.audio.input`) is tuned from
 * config — a `noise_reduction` profile and a MILD server_vad raise — so a fan
 * / AC / distant chatter is less likely to be taken as speech, without
 * clipping the onset of a real utterance or disabling barge-in.
 */
final class OpenAiRealtimeProvider implements RealtimeVoiceProvider
{
    /**
     * @param  array<string, scalar>  $turnDetection  turn-detection config:
     *                                                `type` (server_vad | semantic_vad); for server_vad also
     *                                                `threshold` / `prefix_padding_ms` / `silence_duration_ms`;
     *                                                for semantic_vad also `eagerness` (low|medium|high|auto)
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $voice,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly array $turnDetection = [],
        private readonly ?string $noiseReduction = null,
    ) {}

    public function createClientSession(RealtimeSessionRequest $request): RealtimeSessionToken
    {
        if (trim($this->apiKey) === '') {
            throw ProviderException::missingCredentials();
        }

        $voice = $request->voiceOverride ?? $this->voice;

        $audio = ['output' => ['voice' => $voice]];
        $input = $this->inputAudio();
        if ($input !== []) {
            $audio['input'] = $input;
        }

        try {
            $response = Http::asJson()
                ->withToken($this->apiKey)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->retry(1, 250, throw: false)
                ->post($this->endpoint(), [
                    'session' => [
                        'type' => 'realtime',
                        'model' => $this->model,
                        'instructions' => $request->instructions,
                        'audio' => $audio,
                    ],
                ]);
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

        return $this->toToken($response, $voice);
    }

    /**
     * The OpenAI `session.audio.input` block, built from config. Empty when no
     * tuning is configured (keeps the request minimal for tests / defaults).
     *
     * @return array<string, mixed>
     */
    private function inputAudio(): array
    {
        $input = [];

        if ($this->noiseReduction !== null && $this->noiseReduction !== '' && $this->noiseReduction !== 'off') {
            $input['noise_reduction'] = ['type' => $this->noiseReduction];
        }

        if ($this->turnDetection !== []) {
            $type = isset($this->turnDetection['type']) && is_string($this->turnDetection['type']) && $this->turnDetection['type'] !== ''
                ? $this->turnDetection['type']
                : 'server_vad';

            $vad = [
                'type' => $type,
                'create_response' => true,
                'interrupt_response' => true,
            ];

            if ($type === 'semantic_vad') {
                // Model-decided turn end. `eagerness` is how quickly it cuts in;
                // no fixed thresholds/timeouts apply.
                if (isset($this->turnDetection['eagerness']) && is_string($this->turnDetection['eagerness']) && $this->turnDetection['eagerness'] !== '') {
                    $vad['eagerness'] = $this->turnDetection['eagerness'];
                }
            } else {
                if (isset($this->turnDetection['threshold']) && is_numeric($this->turnDetection['threshold'])) {
                    $vad['threshold'] = (float) $this->turnDetection['threshold'];
                }
                if (isset($this->turnDetection['prefix_padding_ms']) && is_numeric($this->turnDetection['prefix_padding_ms'])) {
                    $vad['prefix_padding_ms'] = (int) $this->turnDetection['prefix_padding_ms'];
                }
                if (isset($this->turnDetection['silence_duration_ms']) && is_numeric($this->turnDetection['silence_duration_ms'])) {
                    $vad['silence_duration_ms'] = (int) $this->turnDetection['silence_duration_ms'];
                }
            }

            $input['turn_detection'] = $vad;
        }

        return $input;
    }

    private function toToken(Response $response, string $voice): RealtimeSessionToken
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw ProviderException::malformedResponse();
        }

        // Accept the flat client-secrets shape ({value, expires_at, session})
        // and the nested shape ({client_secret: {value, expires_at}}).
        $secretNode = is_array($body['client_secret'] ?? null) ? $body['client_secret'] : $body;

        $value = $secretNode['value'] ?? null;
        $expiresAt = $secretNode['expires_at'] ?? ($body['expires_at'] ?? null);

        if (! is_string($value) || trim($value) === '') {
            throw ProviderException::malformedResponse();
        }

        $session = is_array($body['session'] ?? null) ? $body['session'] : [];
        $model = is_string($session['model'] ?? null) && $session['model'] !== '' ? $session['model'] : $this->model;

        try {
            return new RealtimeSessionToken(
                clientSecret: $value,
                expiresAt: is_int($expiresAt) && $expiresAt > 0 ? $expiresAt : Carbon::now()->addMinute()->getTimestamp(),
                model: $model,
                voice: $voice,
            );
        } catch (\InvalidArgumentException) {
            // e.g. the guard that rejects a standing `sk-…` key slipping through.
            throw ProviderException::malformedResponse();
        }
    }

    /**
     * Pull a SHORT, enum-like field out of the vendor error envelope. Anything
     * that is not a compact token (a free-text sentence) is dropped so no
     * vendor prose reaches a log or the operator. Mirrors
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
        return rtrim($this->baseUrl, '/').'/realtime/client_secrets';
    }
}
