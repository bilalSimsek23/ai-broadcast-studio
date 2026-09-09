<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Contracts\ImageGenerationProvider;
use App\AI\Contracts\RealtimeVoiceProvider;
use App\AI\Providers\Fake\FakeImageProvider;
use App\AI\Providers\Fake\FakeRealtimeVoiceProvider;
use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Providers\OpenAi\OpenAiImageProvider;
use App\AI\Providers\OpenAi\OpenAiRealtimeProvider;
use App\AI\Providers\OpenAi\OpenAiTextProvider;
use App\AI\Resolution\LogicalModelResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The one Laravel-aware file in the AI layer. It hands the framework-agnostic
 * {@see LogicalModelResolver} a plain `ai.text` config array + a driver-factory
 * closure, and wires each concrete driver — text and realtime voice —
 * including the credential-bearing OpenAI adapters, whose secrets come only
 * from `config('ai.*.connections.openai')`.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerText();
        $this->registerRealtime();
        $this->registerImage();
    }

    private function registerText(): void
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
                sendSamplingParameters: ($connection['send_sampling_params'] ?? false) === true,
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

    private function registerRealtime(): void
    {
        $this->app->singleton(FakeRealtimeVoiceProvider::class);

        $this->app->singleton(OpenAiRealtimeProvider::class, static function (Application $app): OpenAiRealtimeProvider {
            $config = $app->make('config');

            $connection = $config->get('ai.realtime.connections.openai', []);
            $connection = is_array($connection) ? $connection : [];

            $turnDetection = $config->get('ai.realtime.audio.turn_detection', []);
            $noiseReduction = $config->get('ai.realtime.audio.noise_reduction');

            return new OpenAiRealtimeProvider(
                apiKey: is_string($connection['api_key'] ?? null) ? $connection['api_key'] : '',
                baseUrl: is_string($connection['base_url'] ?? null) && trim($connection['base_url']) !== ''
                    ? $connection['base_url']
                    : 'https://api.openai.com/v1',
                model: is_string($connection['model'] ?? null) && trim($connection['model']) !== ''
                    ? $connection['model']
                    : 'gpt-realtime',
                voice: is_string($connection['voice'] ?? null) && trim($connection['voice']) !== ''
                    ? $connection['voice']
                    : 'marin',
                timeoutSeconds: self::positiveInt($connection['timeout'] ?? null, 15),
                connectTimeoutSeconds: self::positiveInt($connection['connect_timeout'] ?? null, 10),
                turnDetection: self::scalarMap(is_array($turnDetection) ? $turnDetection : []),
                noiseReduction: is_string($noiseReduction) && $noiseReduction !== '' ? $noiseReduction : null,
            );
        });

        $this->app->singleton(RealtimeVoiceProvider::class, static function (Application $app): RealtimeVoiceProvider {
            $driver = $app->make('config')->get('ai.realtime.driver');

            return $driver === 'openai'
                ? $app->make(OpenAiRealtimeProvider::class)
                : $app->make(FakeRealtimeVoiceProvider::class);
        });
    }

    private function registerImage(): void
    {
        $this->app->singleton(FakeImageProvider::class);

        $this->app->singleton(OpenAiImageProvider::class, static function (Application $app): OpenAiImageProvider {
            $connection = $app->make('config')->get('ai.image.connections.openai', []);
            $connection = is_array($connection) ? $connection : [];

            return new OpenAiImageProvider(
                apiKey: is_string($connection['api_key'] ?? null) ? $connection['api_key'] : '',
                baseUrl: is_string($connection['base_url'] ?? null) && trim($connection['base_url']) !== ''
                    ? $connection['base_url']
                    : 'https://api.openai.com/v1',
                model: is_string($connection['model'] ?? null) && trim($connection['model']) !== ''
                    ? $connection['model']
                    : 'gpt-image-1',
                quality: is_string($connection['quality'] ?? null) && trim($connection['quality']) !== ''
                    ? $connection['quality']
                    : 'auto',
                timeoutSeconds: self::positiveInt($connection['timeout'] ?? null, 60),
                connectTimeoutSeconds: self::positiveInt($connection['connect_timeout'] ?? null, 10),
            );
        });

        $this->app->singleton(ImageGenerationProvider::class, static function (Application $app): ImageGenerationProvider {
            $driver = $app->make('config')->get('ai.image.driver');

            return $driver === 'openai'
                ? $app->make(OpenAiImageProvider::class)
                : $app->make(FakeImageProvider::class);
        });
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * Keep only string-keyed scalar entries (turn-detection config from
     * config/ai.php: string `type` / `eagerness`, numeric server_vad tuning).
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, scalar>
     */
    private static function scalarMap(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
