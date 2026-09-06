<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EpisodeAiPersonaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_episode_can_have_multiple_ai_personas_with_pivot_data(): void
    {
        $episode = Episode::factory()->create();
        $host = AiPersona::factory()->active()->create();
        $guest = AiPersona::factory()->active()->create();

        $episode->aiPersonas()->attach($host, ['sort_order' => 0, 'episode_instructions' => 'lead the debate']);
        $episode->aiPersonas()->attach($guest, ['sort_order' => 1, 'episode_instructions' => 'take the opposing view']);

        $episode->load('aiPersonas');
        $this->assertCount(2, $episode->aiPersonas);

        // orderByPivot('sort_order') => host first.
        $this->assertTrue($episode->aiPersonas->first()->is($host));
        $this->assertSame(0, $episode->aiPersonas->first()->pivot->sort_order);
        $this->assertIsInt($episode->aiPersonas->first()->pivot->sort_order);
        $this->assertSame('lead the debate', $episode->aiPersonas->first()->pivot->episode_instructions);

        // Relationship is symmetric.
        $this->assertTrue($host->load('episodes')->episodes->first()->is($episode));
    }

    public function test_the_same_persona_cannot_be_assigned_to_the_same_episode_twice(): void
    {
        $episode = Episode::factory()->create();
        $persona = AiPersona::factory()->active()->create();

        $episode->aiPersonas()->attach($persona);

        $this->expectException(QueryException::class);
        $episode->aiPersonas()->attach($persona);
    }

    public function test_a_persona_that_is_cast_in_an_episode_cannot_be_deleted(): void
    {
        $episode = Episode::factory()->create();
        $persona = AiPersona::factory()->create();
        $episode->aiPersonas()->attach($persona);

        $this->expectException(QueryException::class);
        $persona->delete();
    }

    public function test_deleting_an_episode_clears_its_line_up_rows(): void
    {
        $episode = Episode::factory()->create();
        $persona = AiPersona::factory()->create();
        $episode->aiPersonas()->attach($persona);

        $episode->delete();

        $this->assertDatabaseCount('episode_ai_persona', 0);
        // The persona itself survives.
        $this->assertDatabaseHas('ai_personas', ['id' => $persona->id]);
    }

    public function test_detaching_then_reattaching_the_same_persona_is_allowed(): void
    {
        $episode = Episode::factory()->create();
        $persona = AiPersona::factory()->create();

        $episode->aiPersonas()->attach($persona);
        $episode->aiPersonas()->detach($persona);
        $episode->aiPersonas()->attach($persona, ['sort_order' => 5]);

        $this->assertSame(1, DB::table('episode_ai_persona')->count());
    }

    public function test_the_pivot_row_can_be_built_through_its_factory(): void
    {
        $row = EpisodeAiPersona::factory()->position(2)->create();

        $this->assertDatabaseHas('episode_ai_persona', ['id' => $row->id, 'sort_order' => 2]);
        $this->assertIsInt($row->fresh()->sort_order);
        $this->assertNotNull(Episode::find($row->episode_id));
        $this->assertNotNull(AiPersona::find($row->ai_persona_id));
    }

    public function test_pivot_mass_assignment_is_whitelisted(): void
    {
        $row = new EpisodeAiPersona;
        $row->fill(['sort_order' => 3, 'not_a_real_column' => 'x']);

        $this->assertSame(3, $row->sort_order);
        $this->assertFalse($row->isFillable('not_a_real_column'));
        $this->assertArrayNotHasKey('not_a_real_column', $row->getAttributes());
    }
}
