<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * A logical provider key was requested that is not registered in
 * config('ai.text.providers'). The resolver never falls back to an arbitrary
 * provider — it fails here instead.
 *
 * Neither the requested key (caller-supplied, possibly a mistakenly-pasted
 * credential) nor the list of registered keys is included in the message
 * (CLAUDE.md §6). The available keys are discoverable from the config file.
 */
final class UnknownProviderKey extends AiConfigurationException
{
    public static function unknown(): self
    {
        return new self(
            'The requested logical text provider key is not registered in config("ai.text.providers").',
        );
    }
}
