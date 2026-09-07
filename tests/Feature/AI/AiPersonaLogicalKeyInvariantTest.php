<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\TextGenerationProvider;
use App\AI\Resolution\LogicalModelResolver;
use App\Exceptions\InvalidLogicalConfigKey;
use App\Models\AiPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-0005 expanded config/ai.php with a `text` resolver section. This guards
 * that the TASK-0001 AiPersona logical-key invariant is unaffected: a persona
 * still validates only against config('ai.persona.*'), and the new
 * config('ai.text.*') keys do not silently widen what it accepts.
 */
class AiPersonaLogicalKeyInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_persona_still_persists_with_permitted_logical_keys(): void
    {
        $persona = AiPersona::create([
            'name' => 'Hikmet',
            'ai_provider' => 'default',
            'ai_model' => 'large',
            'voice_provider' => 'default',
            'voice_id' => 'analyst',
        ]);

        $this->assertTrue($persona->exists);
        $this->assertSame('large', $persona->fresh()->ai_model);
    }

    public function test_a_vendor_name_is_still_rejected_on_a_persona_binding_column(): void
    {
        $this->expectException(InvalidLogicalConfigKey::class);

        AiPersona::create(['name' => 'Leaky', 'ai_provider' => 'openai']);
    }

    public function test_the_persona_allow_list_is_still_the_persona_config_not_the_text_resolver(): void
    {
        // 'fake-balanced-v1' is a real vendor model id in config('ai.text'),
        // but it is NOT a permitted persona logical key and must be rejected.
        $this->expectException(InvalidLogicalConfigKey::class);

        AiPersona::create(['name' => 'Confused', 'ai_model' => 'fake-balanced-v1']);
    }

    public function test_every_persona_provider_and_model_key_is_resolvable_by_the_text_layer(): void
    {
        $resolver = $this->app->make(LogicalModelResolver::class);

        foreach (config('ai.persona.ai_provider') as $providerKey) {
            $this->assertInstanceOf(
                TextGenerationProvider::class,
                $resolver->provider($providerKey),
            );
        }

        foreach (config('ai.persona.ai_model') as $modelKey) {
            $this->assertNotSame('', $resolver->model($modelKey)->vendorModelId);
        }
    }
}
