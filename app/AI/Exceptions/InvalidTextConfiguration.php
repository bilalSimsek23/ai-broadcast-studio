<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * config('ai.text.*') is structurally wrong: a missing section, a malformed
 * provider/model binding, or a driver that does not resolve to an
 * App\AI\Contracts\TextGenerationProvider. Distinct from
 * {@see UnknownProviderKey} / {@see UnknownModelKey}, which mean a caller asked
 * for a key that simply is not registered.
 *
 * Messages name only the LOGICAL key whose binding is broken — and that key
 * only after it has been confirmed to exist in config — run through
 * {@see SafeIdentifier}. A raw config VALUE (driver class, provider string,
 * vendor model id) is never echoed.
 */
final class InvalidTextConfiguration extends AiConfigurationException
{
    public static function missingSection(string $section): self
    {
        return new self(sprintf(
            'config("ai.text.%s") is missing or is not an array.',
            SafeIdentifier::format($section),
        ));
    }

    public static function providerBinding(string $logicalProvider): self
    {
        return new self(sprintf(
            'The logical text provider [%s] is misconfigured: expected an array with a non-blank string "driver".',
            SafeIdentifier::format($logicalProvider),
        ));
    }

    public static function modelBinding(string $logicalModel): self
    {
        return new self(sprintf(
            'The logical text model [%s] is misconfigured: expected an array with non-blank string "provider" and '
            .'"model" values and an optional "parameters" array.',
            SafeIdentifier::format($logicalModel),
        ));
    }

    public static function modelReferencesUnknownProvider(string $logicalModel): self
    {
        return new self(sprintf(
            'The logical text model [%s] references a provider that is not registered in config("ai.text.providers").',
            SafeIdentifier::format($logicalModel),
        ));
    }

    public static function driver(string $logicalProvider, string $reason): self
    {
        return new self(sprintf(
            'The driver configured for logical text provider [%s] is invalid: %s.',
            SafeIdentifier::format($logicalProvider),
            $reason,
        ));
    }

    public static function driverNotRegistered(string $logicalProvider): self
    {
        return self::driver($logicalProvider, 'it is not registered in config("ai.text.drivers")');
    }

    public static function driverNotConstructable(string $logicalProvider): self
    {
        return self::driver($logicalProvider, 'the container could not construct it');
    }

    public static function driverNotAProvider(string $logicalProvider): self
    {
        return self::driver($logicalProvider, 'it does not implement the TextGenerationProvider contract');
    }
}
