<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Resolution\LogicalModelResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The one Laravel-aware file in `app/AI/**`. It adapts the framework
 * (config repository + container) to the framework-agnostic text layer:
 *
 * - {@see LogicalModelResolver} gets a plain `ai.text` config array and a
 *   driver-factory closure; it never touches Illuminate itself.
 * - {@see FakeTextProvider} is a singleton so a test can script it and read
 *   back the recorded calls through the very instance the resolver hands out.
 *
 * {@see GenerateText} needs no explicit binding — it is autowired from the
 * resolver.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeTextProvider::class);

        $this->app->singleton(LogicalModelResolver::class, static function (Application $app): LogicalModelResolver {
            $config = $app->make('config')->get('ai.text', []);

            return new LogicalModelResolver(
                is_array($config) ? $config : [],
                static fn (string $class): object => $app->make($class),
            );
        });
    }
}
