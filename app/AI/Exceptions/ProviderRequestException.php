<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * The provider accepted the connection but rejected the request with a non-2xx
 * status (auth failure, rate limit, bad request, upstream error).
 *
 * Only the HTTP status and, when the vendor supplied them in a recognised
 * short/enumerated form, an error `type` and `code` are carried. The vendor's
 * free-text error message and the raw response body are deliberately dropped —
 * they can echo request content or otherwise be sensitive, and are never shown
 * to an operator (CLAUDE.md §5, §6).
 */
final class ProviderRequestException extends ProviderException
{
    private function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, ?string $type = null, ?string $code = null): self
    {
        $detail = '';

        if ($type !== null) {
            $detail .= sprintf(', type: %s', $type);
        }

        if ($code !== null) {
            $detail .= sprintf(', code: %s', $code);
        }

        return new self(
            sprintf('The OpenAI text provider rejected the request (HTTP %d%s).', $status, $detail),
            $status,
        );
    }
}
