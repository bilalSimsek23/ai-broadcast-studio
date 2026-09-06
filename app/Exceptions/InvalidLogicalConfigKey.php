<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when domain data would persist a value that is not a registered
 * logical configuration key - e.g. a vendor name or raw model id on an
 * AiPersona binding column (CLAUDE.md section 5).
 *
 * The rejected value is deliberately NOT included in the message: it may be a
 * mistakenly-pasted credential, and this exception can reach logs / consoles /
 * monitoring (CLAUDE.md security - no secrets in logs).
 */
final class InvalidLogicalConfigKey extends InvalidArgumentException
{
    public static function for(string $model, string $field, string $configPath): self
    {
        return new self(sprintf(
            '%s.%s must be a registered logical key from config("%s") '
            .'(vendor names, base URLs and raw model ids must not be persisted on domain models). '
            .'The supplied value was rejected and is not shown.',
            $model,
            $field,
            $configPath,
        ));
    }
}
