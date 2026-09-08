<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * Base type for a RUNTIME failure of a concrete AI adapter — text generation or
 * realtime voice — (a transport error, a rejected request, an unintelligible
 * response), as opposed to {@see AiConfigurationException}, which means the
 * layer is mis-wired.
 *
 * Its messages are safe to log and to show an operator: they never contain the
 * API key, the base URL, request/response bodies, or any free-text the vendor
 * returned (CLAUDE.md §5, §6). A raw vendor exception never escapes the
 * adapter — it is always translated into one of these first.
 */
class ProviderException extends RuntimeException
{
    public static function missingCredentials(): self
    {
        return new self(
            'The OpenAI provider is not configured: no API key is available from the environment.',
        );
    }

    public static function malformedResponse(): self
    {
        return new self(
            'The OpenAI provider returned a response that could not be understood.',
        );
    }
}
