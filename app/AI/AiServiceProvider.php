<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Providers\OpenAi\OpenAiTextProvider;
use App\AI\Resolution\LogicalModelResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The one Laravel-aware file in the AI text layer. It hands the
 * framework-agnostic {@see LogicalModelResolver} a plain `ai.text` config array
 * and a driver-factory closure, and wires each concrete driver — including the
 * credential-bearing OpenAI adapter, whose secrets come only from
 * config('ai.text.connections.openai').
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeTextProvider::class);

        $this->app->singleton(OpenAiTextProvider::class, static function (Application $app): OpenAiTextProvider {
            $connection = $app->make('config')->get('ai.text.connections.openai', []);
            $connection = is_array($connection) ? $connection : [];

            return new OpenAiTextProvider(
                apiKey: is_string($connection['api_key'] ?? null) ? $connection['api_key'] : '',
                baseUrl: is_string($connection['base_url'] ?? null) && trim($connection['base_url']) !== ''
                    ? $connection['base_url']
                    : 'https://api.openai.com/v1',
                timeoutSeconds: self::positiveInt($connection['timeout'] ?? null, 30),
                connectTimeoutSeconds: self::positiveInt($connection['connect_timeout'] ?? null, 10),
            );
        });

        $this->app->singleton(LogicalModelResolver::class, static function (Application $app): LogicalModelResolver {
            $config = $app->make('config')->get('ai.text', []);

            return new LogicalModelResolver(
                is_array($config) ? $config : [],
                static fn (string $class): object => $app->make($class),
            );
        });
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }
}
