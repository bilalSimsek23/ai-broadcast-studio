<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * A logical model key was requested that is not registered in
 * config('ai.text.models'). The resolver never falls back to an arbitrary
 * model — it fails here instead.
 *
 * Neither the requested key (caller-supplied, possibly a mistakenly-pasted
 * credential) nor the list of registered keys is included in the message
 * (CLAUDE.md §6). The available keys are discoverable from the config file.
 */
final class UnknownModelKey extends AiConfigurationException
{
    public static function unknown(): self
    {
        return new self(
            'The requested logical text model key is not registered in config("ai.text.models").',
        );
    }
}
