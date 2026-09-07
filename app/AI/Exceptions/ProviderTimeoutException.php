<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * The provider did not answer inside its configured time bound (a connect
 * timeout, a read timeout, or a dropped connection). The caller should degrade
 * gracefully — a provider round-trip is never allowed to hang a request
 * (CLAUDE.md §5, project-context hard constraint 2).
 */
final class ProviderTimeoutException extends ProviderException
{
    public static function afterSeconds(int $seconds): self
    {
        return new self(sprintf(
            'The OpenAI text provider did not respond within %d seconds.',
            $seconds,
        ));
    }
}
