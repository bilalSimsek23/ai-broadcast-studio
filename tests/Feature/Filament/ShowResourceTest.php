<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ShowStatus;
use App\Filament\Resources\Shows\Pages\CreateShow;
use App\Filament\Resources\Shows\Pages\ListShows;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShowResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_an_admin_can_create_a_show(): void
    {
        Livewire::test(CreateShow::class)
            ->fillForm([
                'name' => 'The Debate Room',
                'slug' => 'the-debate-room',
                'description' => 'Nightly debate.',
                'status' => ShowStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('shows', [
            'name' => 'The Debate Room',
            'slug' => 'the-debate-room',
            'status' => ShowStatus::Active->value,
        ]);
    }

    public function test_show_form_validation(): void
    {
        Show::factory()->create(['slug' => 'taken']);

        Livewire::test(CreateShow::class)
            ->fillForm(['name' => null, 'slug' => 'taken'])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'slug' => 'unique',
            ]);

        Livewire::test(CreateShow::class)
            ->fillForm(['name' => 'X', 'slug' => 'not a slug!'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_slug_must_already_be_canonical_and_collisions_are_form_errors_not_db_errors(): void
    {
        Show::factory()->create(['slug' => 'foo-bar']);

        // Uppercase / underscore: rejected by the regex rule, not "fixed" then
        // collided at the database.
        Livewire::test(CreateShow::class)
            ->fillForm(['name' => 'A', 'slug' => 'Foo-Bar'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateShow::class)
            ->fillForm(['name' => 'B', 'slug' => 'foo_bar'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        // Exact canonical collision: a unique form error.
        Livewire::test(CreateShow::class)
            ->fillForm(['name' => 'C', 'slug' => 'foo-bar'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        $this->assertSame(1, Show::whereSlug('foo-bar')->count());
    }

    public function test_status_is_persisted_as_the_enum_value(): void
    {
        Livewire::test(CreateShow::class)
            ->fillForm(['name' => 'Enum Show', 'slug' => 'enum-show', 'status' => ShowStatus::Inactive->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $show = Show::whereName('Enum Show')->sole();
        $this->assertSame(ShowStatus::Inactive, $show->status);
    }

    public function test_a_show_with_episodes_cannot_be_deleted_through_the_ui(): void
    {
        $withEpisodes = Show::factory()->create();
        Episode::factory()->forShow($withEpisodes)->create();
        $deletable = Show::factory()->create();

        Livewire::test(ListShows::class)
            ->assertTableActionHidden('delete', $withEpisodes)
            ->assertTableActionVisible('delete', $deletable);

        // The protected show survives; the deletable one can go.
        Livewire::test(ListShows::class)->callTableAction('delete', $deletable);

        $this->assertDatabaseHas('shows', ['id' => $withEpisodes->id]);
        $this->assertDatabaseMissing('shows', ['id' => $deletable->id]);
    }

    public function test_the_archive_action_sets_status_without_deleting(): void
    {
        $show = Show::factory()->create(['status' => ShowStatus::Active->value]);
        Episode::factory()->forShow($show)->create();

        Livewire::test(ListShows::class)->callTableAction('archive', $show);

        $this->assertSame(ShowStatus::Archived, $show->refresh()->status);
        $this->assertDatabaseHas('shows', ['id' => $show->id]);
    }
}
