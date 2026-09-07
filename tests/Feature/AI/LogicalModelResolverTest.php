<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Exceptions\InvalidGenerationParameters;
use App\AI\Exceptions\InvalidTextConfiguration;
use App\AI\Exceptions\UnknownModelKey;
use App\AI\Exceptions\UnknownProviderKey;
use App\AI\Providers\Fake\FakeTextProvider;
use App\AI\Resolution\LogicalModelResolver;
use Tests\TestCase;

class LogicalModelResolverTest extends TestCase
{
    private function resolver(): LogicalModelResolver
    {
        return $this->app->make(LogicalModelResolver::class);
    }

    public function test_it_resolves_a_logical_provider_key_to_a_provider_instance(): void
    {
        $provider = $this->resolver()->provider('default');

        $this->assertInstanceOf(TextGenerationProvider::class, $provider);
        $this->assertInstanceOf(FakeTextProvider::class, $provider);
    }

    public function test_it_hands_back_the_container_singleton_for_the_fake_driver(): void
    {
        $this->assertSame(
            $this->app->make(FakeTextProvider::class),
            $this->resolver()->provider('fast'),
        );
    }

    public function test_it_resolves_a_logical_model_key_to_provider_vendor_id_and_defaults(): void
    {
        $model = $this->resolver()->model('host_rebuttal');

        $this->assertInstanceOf(FakeTextProvider::class, $model->provider);
        $this->assertSame('host_rebuttal', $model->logicalProvider);
        $this->assertSame('host_rebuttal', $model->logicalModel);
        $this->assertSame('fake-rebuttal-v1', $model->vendorModelId);
        $this->assertSame(0.9, $model->defaultParameters->temperature);
        $this->assertSame(600, $model->defaultParameters->maxOutputTokens);
    }

    public function test_the_shipped_persona_model_keys_all_resolve(): void
    {
        foreach (config('ai.persona.ai_model') as $logicalModelKey) {
            $model = $this->resolver()->model($logicalModelKey);
            $this->assertNotSame('', $model->vendorModelId);
        }
    }

    public function test_an_unknown_provider_key_throws_and_does_not_fall_back(): void
    {
        $this->expectException(UnknownProviderKey::class);

        $this->resolver()->provider('no-such-provider');
    }

    public function test_an_unknown_model_key_throws_and_does_not_fall_back(): void
    {
        $this->expectException(UnknownModelKey::class);

        $this->resolver()->model('no-such-model');
    }

    public function test_a_missing_config_section_is_a_configuration_error(): void
    {
        config()->set('ai.text.models', null);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->model('default');
    }

    public function test_a_model_that_points_at_an_unknown_provider_is_a_configuration_error(): void
    {
        config()->set('ai.text.models.broken', [
            'provider' => 'ghost',
            'model' => 'x',
            'parameters' => [],
        ]);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->model('broken');
    }

    public function test_a_provider_bound_to_an_unregistered_driver_is_a_configuration_error(): void
    {
        config()->set('ai.text.providers.brokenp', ['driver' => 'ghost-driver']);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->provider('brokenp');
    }

    public function test_a_driver_class_that_is_not_a_provider_is_a_configuration_error(): void
    {
        config()->set('ai.text.drivers.notaprovider', \stdClass::class);
        config()->set('ai.text.providers.np', ['driver' => 'notaprovider']);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->provider('np');
    }

    public function test_malformed_model_parameters_are_rejected_on_resolution(): void
    {
        config()->set('ai.text.models.badparams', [
            'provider' => 'default',
            'model' => 'x',
            'parameters' => ['temperature' => 9.9],
        ]);

        $this->expectException(InvalidGenerationParameters::class);

        $this->resolver()->model('badparams');
    }

    public function test_a_whitespace_only_vendor_model_id_is_a_configuration_error(): void
    {
        config()->set('ai.text.models.blankmodel', [
            'provider' => 'default',
            'model' => "   \t",
            'parameters' => [],
        ]);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->model('blankmodel');
    }

    public function test_a_whitespace_only_provider_value_on_a_model_binding_is_a_configuration_error(): void
    {
        config()->set('ai.text.models.blankprovider', [
            'provider' => '  ',
            'model' => 'x',
            'parameters' => [],
        ]);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->model('blankprovider');
    }

    public function test_a_whitespace_only_driver_value_is_a_configuration_error(): void
    {
        config()->set('ai.text.providers.blankdriver', ['driver' => '   ']);

        $this->expectException(InvalidTextConfiguration::class);

        $this->resolver()->provider('blankdriver');
    }

    public function test_an_unknown_key_never_appears_in_the_exception_message(): void
    {
        // A value mistakenly passed where a logical key was expected (imagine a
        // pasted credential) must not be reflected back into the message.
        $mistakenlySupplied = 'a-value-that-should-never-be-echoed-back-1234567890';

        try {
            $this->resolver()->model($mistakenlySupplied);
            $this->fail('expected '.UnknownModelKey::class);
        } catch (UnknownModelKey $e) {
            $this->assertStringNotContainsString($mistakenlySupplied, $e->getMessage());
        }

        try {
            $this->resolver()->provider($mistakenlySupplied);
            $this->fail('expected '.UnknownProviderKey::class);
        } catch (UnknownProviderKey $e) {
            $this->assertStringNotContainsString($mistakenlySupplied, $e->getMessage());
        }
    }

    public function test_a_configuration_error_names_the_logical_key_only_not_the_raw_binding_value(): void
    {
        $rawDriverValue = 'a-driver-string-that-must-not-be-reflected';
        config()->set('ai.text.providers.leaky', ['driver' => $rawDriverValue]);

        try {
            $this->resolver()->provider('leaky');
            $this->fail('expected '.InvalidTextConfiguration::class);
        } catch (InvalidTextConfiguration $e) {
            $this->assertStringNotContainsString($rawDriverValue, $e->getMessage());
            $this->assertStringContainsString('leaky', $e->getMessage()); // the safe logical key
        }
    }
}
