<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * Formats an identifier for inclusion in an exception message that may reach
 * logs, consoles and monitoring.
 *
 * Only a short, plain identifier is emitted verbatim; anything else (a long
 * token, a URL, a value with unexpected characters) is replaced with a
 * redaction marker (CLAUDE.md §6). It is applied as defence-in-depth to the
 * config KEYS the resolver names in {@see InvalidTextConfiguration} — those are
 * already proven to exist in config before being passed here. Caller-supplied
 * keys are never passed through this class: they are simply never echoed.
 */
final class SafeIdentifier
{
    public const REDACTED = '[redacted]';

    private const PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

    public static function format(string $value): string
    {
        return preg_match(self::PATTERN, $value) === 1 ? $value : self::REDACTED;
    }
}
