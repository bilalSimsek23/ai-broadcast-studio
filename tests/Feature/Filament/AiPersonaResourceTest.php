<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AiPersonaStatus;
use App\Filament\Resources\AiPersonas\Pages\CreateAiPersona;
use App\Filament\Resources\AiPersonas\Pages\EditAiPersona;
use App\Filament\Resources\AiPersonas\Pages\ListAiPersonas;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiPersonaResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_an_admin_can_create_an_ai_persona_with_a_logical_provider_key(): void
    {
        Livewire::test(CreateAiPersona::class)
            ->fillForm([
                'name' => 'Dr. Vega',
                'title' => 'Economist',
                'ai_provider' => 'fast',
                'status' => AiPersonaStatus::Active->value,
                'screen_settings.display_name' => 'Vega',
                'screen_settings.display_title' => 'Ekonomist',
                'screen_settings.avatar_position' => 'left',
                'screen_settings.avatar_size' => 120,
                'screen_settings.lower_third_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $persona = AiPersona::whereName('Dr. Vega')->sole();
        $this->assertSame('fast', $persona->ai_provider);
        $this->assertSame(AiPersonaStatus::Active, $persona->status);
    }

    public function test_a_vendor_name_cannot_be_chosen_for_a_logical_key_field(): void
    {
        // The Select only offers config/ai.php keys, so an out-of-list value
        // fails Filament's "in" validation before it ever reaches the model.
        Livewire::test(CreateAiPersona::class)
            ->fillForm(['name' => 'Leaky', 'ai_provider' => 'openai'])
            ->call('create')
            ->assertHasFormErrors(['ai_provider']);

        $this->assertDatabaseMissing('ai_personas', ['name' => 'Leaky']);
    }

    public function test_screen_settings_persist_as_structured_json_and_hydrate_back(): void
    {
        Livewire::test(CreateAiPersona::class)
            ->fillForm([
                'name' => 'Screen Person',
                'screen_settings.display_name' => 'On Air Name',
                'screen_settings.display_title' => 'On Air Title',
                'screen_settings.avatar_position' => 'center',
                'screen_settings.avatar_size' => 90,
                'screen_settings.lower_third_enabled' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $persona = AiPersona::whereName('Screen Person')->sole();

        $this->assertSame([
            'display_name' => 'On Air Name',
            'display_title' => 'On Air Title',
            'avatar_position' => 'center',
            'avatar_size' => 90,
            'lower_third_enabled' => false,
        ], $persona->screen_settings);

        // Edit form re-hydrates the structured fields from the JSON.
        Livewire::test(EditAiPersona::class, ['record' => $persona->getRouteKey()])
            ->assertFormSet([
                'screen_settings.display_name' => 'On Air Name',
                'screen_settings.avatar_position' => 'center',
                'screen_settings.avatar_size' => 90,
                'screen_settings.lower_third_enabled' => false,
            ]);
    }

    public function test_a_persona_used_in_an_episode_line_up_cannot_be_deleted_via_the_ui(): void
    {
        $used = AiPersona::factory()->create();
        Episode::factory()->create()->lineup()->create(['ai_persona_id' => $used->id, 'sort_order' => 0]);
        $free = AiPersona::factory()->create();

        Livewire::test(ListAiPersonas::class)
            ->assertTableActionHidden('delete', $used)
            ->assertTableActionVisible('delete', $free);

        Livewire::test(ListAiPersonas::class)->callTableAction('delete', $free);

        $this->assertDatabaseHas('ai_personas', ['id' => $used->id]);
        $this->assertDatabaseMissing('ai_personas', ['id' => $free->id]);
    }
}
