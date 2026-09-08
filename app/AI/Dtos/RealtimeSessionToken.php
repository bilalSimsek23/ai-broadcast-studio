<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * The vendor-neutral result of opening a realtime voice session: a SHORT-LIVED
 * ephemeral client secret the browser uses to establish its own WebRTC
 * connection, plus the model/voice actually in effect and the secret's
 * expiry.
 *
 * This DTO must NEVER carry the standing API key. As a guard against a
 * misconfigured adapter, the constructor rejects a secret that looks like a
 * standard OpenAI key (`sk-…`).
 */
final readonly class RealtimeSessionToken
{
    public function __construct(
        public string $clientSecret,
        public int $expiresAt,
        public string $model,
        public string $voice,
    ) {
        if (trim($clientSecret) === '') {
            throw new InvalidArgumentException('A realtime session token needs a non-empty client secret.');
        }

        if (str_starts_with(ltrim($clientSecret), 'sk-')) {
            throw new InvalidArgumentException('A realtime client secret must be an ephemeral token, not a standing API key.');
        }

        if ($expiresAt <= 0) {
            throw new InvalidArgumentException('A realtime session token needs a positive expiry timestamp.');
        }

        if (trim($model) === '' || trim($voice) === '') {
            throw new InvalidArgumentException('A realtime session token needs a model and a voice.');
        }
    }

    /**
     * The shape handed to the browser. No credential beyond the ephemeral
     * secret ever appears here.
     *
     * @return array{client_secret: string, expires_at: int, model: string, voice: string}
     */
    public function toArray(): array
    {
        return [
            'client_secret' => $this->clientSecret,
            'expires_at' => $this->expiresAt,
            'model' => $this->model,
            'voice' => $this->voice,
        ];
    }
}
