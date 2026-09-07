<?php

declare(strict_types=1);

namespace App\AI\Resolution;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Dtos\GenerationParameters;
use App\AI\Exceptions\InvalidTextConfiguration;
use App\AI\Exceptions\UnknownModelKey;
use App\AI\Exceptions\UnknownProviderKey;
use Closure;
use Throwable;

/**
 * Resolves LOGICAL provider / model keys — the only identifiers application and
 * domain code ever names — into a concrete {@see TextGenerationProvider}, the
 * resolved vendor model identifier, and the configured default parameters.
 *
 * Framework-agnostic: it is handed a plain `ai.text` config array and a driver
 * factory closure by App\AI\AiServiceProvider (the one Laravel-aware file).
 *
 * It NEVER falls back silently: an unregistered key throws
 * {@see UnknownProviderKey} / {@see UnknownModelKey}; a structurally broken or
 * blank binding throws {@see InvalidTextConfiguration}.
 */
final class LogicalModelResolver
{
    /** @var array<string, TextGenerationProvider> resolved providers, keyed by logical provider key */
    private array $providers = [];

    /**
     * @param  array<array-key, mixed>  $config  the `ai.text` config array
     * @param  Closure(string): object  $driverFactory  builds a driver instance from its class-string
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $driverFactory,
    ) {}

    public function provider(string $logicalProvider): TextGenerationProvider
    {
        if (isset($this->providers[$logicalProvider])) {
            return $this->providers[$logicalProvider];
        }

        $providers = $this->section('providers');

        if (! array_key_exists($logicalProvider, $providers)) {
            throw UnknownProviderKey::unknown();
        }

        $binding = $providers[$logicalProvider];

        if (! is_array($binding) || ! isset($binding['driver']) || ! is_string($binding['driver']) || trim($binding['driver']) === '') {
            throw InvalidTextConfiguration::providerBinding($logicalProvider);
        }

        return $this->providers[$logicalProvider] = $this->makeDriver($logicalProvider, trim($binding['driver']));
    }

    public function model(string $logicalModel): ResolvedTextModel
    {
        $models = $this->section('models');

        if (! array_key_exists($logicalModel, $models)) {
            throw UnknownModelKey::unknown();
        }

        $binding = $models[$logicalModel];

        if (! is_array($binding)) {
            throw InvalidTextConfiguration::modelBinding($logicalModel);
        }

        $logicalProvider = $binding['provider'] ?? null;
        $vendorModelId = $binding['model'] ?? null;
        $parameters = $binding['parameters'] ?? [];

        if (! is_string($logicalProvider) || trim($logicalProvider) === ''
            || ! is_string($vendorModelId) || trim($vendorModelId) === ''
            || ! is_array($parameters)) {
            throw InvalidTextConfiguration::modelBinding($logicalModel);
        }

        try {
            $provider = $this->provider(trim($logicalProvider));
        } catch (UnknownProviderKey) {
            throw InvalidTextConfiguration::modelReferencesUnknownProvider($logicalModel);
        }

        return new ResolvedTextModel(
            provider: $provider,
            logicalProvider: trim($logicalProvider),
            logicalModel: $logicalModel,
            vendorModelId: trim($vendorModelId),
            defaultParameters: GenerationParameters::fromArray($parameters),
        );
    }

    private function makeDriver(string $logicalProvider, string $driver): TextGenerationProvider
    {
        $drivers = $this->section('drivers');

        if (! array_key_exists($driver, $drivers) || ! is_string($drivers[$driver]) || trim($drivers[$driver]) === '') {
            throw InvalidTextConfiguration::driverNotRegistered($logicalProvider);
        }

        try {
            $instance = ($this->driverFactory)(trim($drivers[$driver]));
        } catch (Throwable) {
            throw InvalidTextConfiguration::driverNotConstructable($logicalProvider);
        }

        if (! $instance instanceof TextGenerationProvider) {
            throw InvalidTextConfiguration::driverNotAProvider($logicalProvider);
        }

        return $instance;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function section(string $name): array
    {
        $value = $this->config[$name] ?? null;

        if (! is_array($value)) {
            throw InvalidTextConfiguration::missingSection($name);
        }

        return $value;
    }
}
