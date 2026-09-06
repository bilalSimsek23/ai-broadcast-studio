<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\AiPersonaStatus;
use App\Exceptions\InvalidLogicalConfigKey;
use App\Models\AiPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiPersonaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ai_persona_can_be_created(): void
    {
        $persona = AiPersona::create([
            'name' => 'Dr. Vega',
            'title' => 'Economist',
            'system_prompt' => 'You argue from first principles.',
            'ai_provider' => 'default',
        ]);

        $this->assertDatabaseHas('ai_personas', [
            'id' => $persona->id,
            'name' => 'Dr. Vega',
            'status' => AiPersonaStatus::Draft->value,
        ]);
        $this->assertNotEmpty($persona->uuid);
        $this->assertSame('uuid', $persona->getRouteKeyName());
    }

    public function test_status_is_cast_to_the_enum(): void
    {
        $persona = AiPersona::factory()->active()->create();

        $this->assertInstanceOf(AiPersonaStatus::class, $persona->status);
        $this->assertSame(AiPersonaStatus::Active, $persona->fresh()->status);
        $this->assertSame('active', DB::table('ai_personas')->where('id', $persona->id)->value('status'));
    }

    public function test_screen_settings_is_cast_to_and_from_json(): void
    {
        $settings = ['position' => 'left', 'scale' => 0.8, 'labels' => ['name', 'title']];

        $persona = AiPersona::factory()->create(['screen_settings' => $settings]);

        // Round-trips as a PHP array.
        $this->assertIsArray($persona->fresh()->screen_settings);
        $this->assertSame($settings, $persona->fresh()->screen_settings);

        // Stored as valid JSON text, not a serialized blob.
        $raw = DB::table('ai_personas')->where('id', $persona->id)->value('screen_settings');
        $this->assertIsString($raw);
        $this->assertSame($settings, json_decode($raw, true));
    }

    public function test_screen_settings_may_be_null(): void
    {
        $persona = AiPersona::factory()->withoutScreenSettings()->create();

        $this->assertNull($persona->fresh()->screen_settings);
    }

    public function test_persisting_a_vendor_name_in_a_binding_field_is_rejected(): void
    {
        $this->expectException(InvalidLogicalConfigKey::class);

        try {
            AiPersona::create(['name' => 'Leaky', 'ai_provider' => 'openai']);
        } finally {
            $this->assertDatabaseMissing('ai_personas', ['name' => 'Leaky']);
        }
    }

    public function test_updating_a_binding_field_with_a_raw_model_id_is_rejected(): void
    {
        $persona = AiPersona::factory()->create(['ai_model' => 'default']);

        try {
            $persona->update(['ai_model' => 'gpt-4o']);
            $this->fail('Expected '.InvalidLogicalConfigKey::class);
        } catch (InvalidLogicalConfigKey) {
            $this->assertSame('default', DB::table('ai_personas')->where('id', $persona->id)->value('ai_model'));
        }
    }

    public function test_an_empty_string_binding_value_is_rejected_like_any_other_unregistered_key(): void
    {
        foreach (['ai_provider', 'ai_model', 'voice_provider', 'voice_id'] as $column) {
            try {
                AiPersona::create(['name' => "empty-{$column}", $column => '']);
                $this->fail('Expected '.InvalidLogicalConfigKey::class." for {$column} => ''");
            } catch (InvalidLogicalConfigKey) {
                $this->assertDatabaseMissing('ai_personas', ['name' => "empty-{$column}"]);
            }
        }
    }

    public function test_the_rejection_exception_never_contains_the_supplied_value(): void
    {
        // Stands in for a value that must never reach logs (a fat-fingered
        // credential would land here). The message must not echo it back.
        $supplied = 'unregistered-vendor-value-must-not-be-logged';

        try {
            AiPersona::create(['name' => 'Redacted', 'ai_provider' => $supplied]);
            $this->fail('Expected '.InvalidLogicalConfigKey::class);
        } catch (InvalidLogicalConfigKey $e) {
            $this->assertStringNotContainsString($supplied, $e->getMessage());
            $this->assertStringContainsString('ai_provider', $e->getMessage());
            $this->assertStringContainsString('ai.persona.ai_provider', $e->getMessage());
        }
    }

    public function test_every_registered_logical_key_can_be_persisted(): void
    {
        foreach (config('ai.persona') as $column => $keys) {
            foreach ($keys as $key) {
                $persona = AiPersona::factory()->create([$column => $key]);
                $this->assertSame($key, $persona->fresh()->{$column}, "{$column} => {$key}");
            }
        }
    }

    public function test_binding_fields_may_be_null(): void
    {
        $persona = AiPersona::factory()->create([
            'ai_provider' => null,
            'ai_model' => null,
            'voice_provider' => null,
            'voice_id' => null,
        ]);

        $this->assertNull($persona->fresh()->ai_provider);
    }

    public function test_provider_binding_fields_are_logical_keys_not_vendor_identifiers(): void
    {
        $vendorish = ['openai', 'anthropic', 'gpt-4', 'gpt-4o', 'gpt-5.6-sol', 'claude',
            'gemini', 'elevenlabs', 'azure', 'aws', 'whisper', 'tts-1'];

        foreach (AiPersona::factory()->count(30)->make() as $persona) {
            foreach (['ai_provider', 'ai_model', 'voice_provider', 'voice_id'] as $field) {
                $value = $persona->{$field};
                if ($value === null) {
                    continue;
                }
                $this->assertNotContains(strtolower($value), $vendorish, "{$field} leaked a vendor identifier");
                $this->assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9_]{1,40}$/',
                    $value,
                    "{$field} value '{$value}' is not a short logical key",
                );
            }
        }
    }
}
