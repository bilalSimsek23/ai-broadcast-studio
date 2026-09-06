<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\ShowStatus;
use App\Models\Episode;
use App\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_show_can_be_created(): void
    {
        $show = Show::create([
            'name' => 'The Debate Room',
            'slug' => 'the-debate-room',
            'description' => 'Nightly current-affairs debate.',
        ]);

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'name' => 'The Debate Room',
            'slug' => 'the-debate-room',
            'status' => ShowStatus::Draft->value,
        ]);
    }

    public function test_uuid_is_generated_automatically_and_used_as_the_route_key(): void
    {
        $show = Show::factory()->create();

        $this->assertNotEmpty($show->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $show->uuid,
        );
        $this->assertSame('uuid', $show->getRouteKeyName());
        $this->assertTrue($show->is(Show::where('uuid', $show->uuid)->firstOrFail()));
    }

    public function test_uuid_and_slug_are_unique(): void
    {
        $first = Show::factory()->create(['slug' => 'flagship']);

        $this->expectException(QueryException::class);
        Show::factory()->create(['uuid' => $first->uuid, 'slug' => 'other']);
    }

    public function test_slug_uniqueness_is_enforced_at_the_database(): void
    {
        Show::factory()->create(['slug' => 'flagship']);

        $this->expectException(QueryException::class);
        Show::factory()->create(['slug' => 'flagship']);
    }

    public function test_status_is_cast_to_the_enum_and_stored_as_its_value(): void
    {
        $show = Show::factory()->create();
        $this->assertInstanceOf(ShowStatus::class, $show->status);
        $this->assertSame(ShowStatus::Draft, $show->status);

        $show->update(['status' => ShowStatus::Active]);

        $this->assertSame(ShowStatus::Active, $show->fresh()->status);
        $this->assertSame('active', DB::table('shows')->where('id', $show->id)->value('status'));
    }

    public function test_a_show_has_many_episodes(): void
    {
        $show = Show::factory()->create();
        Episode::factory()->count(2)->forShow($show)->create();
        Episode::factory()->create(); // unrelated

        $this->assertCount(2, $show->load('episodes')->episodes);
    }
}
