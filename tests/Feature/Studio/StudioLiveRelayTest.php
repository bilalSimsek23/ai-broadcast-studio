<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The cache-backed command/state relay that lets the broadcast screen run
 * remotely: the console writes intent, the screen polls it and posts back
 * state.
 */
class StudioLiveRelayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'relay-access-token';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Persist the relay docs across separate requests within a test.
        config()->set('cache.default', 'database');
        config()->set('ai.realtime.public_access_token', self::TOKEN);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_console_writes_intent_and_the_screen_reads_it(): void
    {
        $this->admin();

        $this->postJson('/studio/live/control', [
            'desired' => 'connected',
            'muted' => true,
            'voiceId' => 'marin',
            'episodeUuid' => 'ep-uuid',
            'durationSeconds' => 0,
            'image' => ['visible' => true, 'ticket' => 'abc123'],
        ])->assertOk()->assertJsonPath('desired', 'connected');

        // The screen reads it with just the access token (no admin session).
        $this->getJson('/studio/live/control?token='.self::TOKEN)
            ->assertOk()
            ->assertJsonPath('desired', 'connected')
            ->assertJsonPath('muted', true)
            ->assertJsonPath('voiceId', 'marin')
            ->assertJsonPath('durationSeconds', 0)
            ->assertJsonPath('image.visible', true)
            ->assertJsonPath('image.ticket', 'abc123');
    }

    public function test_writing_control_bumps_the_revision(): void
    {
        $this->admin();

        $r1 = (int) $this->postJson('/studio/live/control', ['desired' => 'idle'])->assertOk()->json('rev');
        $r2 = (int) $this->postJson('/studio/live/control', ['desired' => 'connected'])->assertOk()->json('rev');

        $this->assertSame($r1 + 1, $r2);
    }

    public function test_only_an_admin_may_write_control(): void
    {
        // Token is not enough for the write side.
        $this->postJson('/studio/live/control?token='.self::TOKEN, ['desired' => 'connected'])
            ->assertUnauthorized();

        $this->postJson('/studio/live/control', ['desired' => 'connected'])->assertUnauthorized();
    }

    public function test_an_invalid_desired_value_is_rejected(): void
    {
        $this->admin();

        $this->postJson('/studio/live/control', ['desired' => 'sideways'])->assertStatus(422);
    }

    public function test_the_screen_posts_state_and_both_the_console_and_a_display_screen_read_it(): void
    {
        // Engine posts with the token only (incl. orb amplitude + engine id).
        $this->postJson('/studio/live/state?token='.self::TOKEN, [
            'connected' => true,
            'muted' => false,
            'remainingSeconds' => 540,
            'status' => 'Yayında',
            'level' => 0.42,
            'engineId' => 'engine-abc',
        ])->assertOk()->assertJsonPath('ok', true);

        // Console (admin).
        $this->admin();
        $this->getJson('/studio/live/state')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('remainingSeconds', 540)
            ->assertJsonPath('level', 0.42)
            ->assertJsonPath('alive', true);
    }

    public function test_state_read_is_open_to_the_access_token_for_a_display_screen(): void
    {
        $this->postJson('/studio/live/state?token='.self::TOKEN, ['level' => 0.7])->assertOk();

        $this->getJson('/studio/live/state?token='.self::TOKEN)
            ->assertOk()
            ->assertJsonPath('level', 0.7);
    }

    public function test_a_missing_state_reports_not_alive(): void
    {
        $this->admin();

        $this->getJson('/studio/live/state')->assertOk()->assertJsonPath('alive', false);
    }

    public function test_a_level_outside_zero_to_one_is_rejected(): void
    {
        $this->postJson('/studio/live/state?token='.self::TOKEN, ['level' => 3])->assertStatus(422);
    }

    public function test_claim_grants_the_first_engine_and_offers_takeover_to_the_next(): void
    {
        $this->postJson('/studio/live/claim?token='.self::TOKEN, ['engineId' => 'engine-one'])
            ->assertOk()
            ->assertJsonPath('granted', true)
            ->assertJsonPath('engineId', 'engine-one');

        $this->postJson('/studio/live/claim?token='.self::TOKEN, ['engineId' => 'engine-two'])
            ->assertOk()
            ->assertJsonPath('granted', false)
            ->assertJsonPath('owner.engineId', 'engine-one');
    }

    public function test_a_force_claim_takes_over_and_the_old_engine_learns_it_lost(): void
    {
        $this->postJson('/studio/live/claim?token='.self::TOKEN, ['engineId' => 'engine-one'])
            ->assertJsonPath('granted', true);

        $this->postJson('/studio/live/claim?token='.self::TOKEN, ['engineId' => 'engine-two', 'force' => true])
            ->assertOk()
            ->assertJsonPath('granted', true)
            ->assertJsonPath('engineId', 'engine-two');

        // engine-one's next heartbeat is denied.
        $this->postJson('/studio/live/claim?token='.self::TOKEN, ['engineId' => 'engine-one'])
            ->assertOk()
            ->assertJsonPath('granted', false)
            ->assertJsonPath('owner.engineId', 'engine-two');
    }

    public function test_claim_needs_an_engine_id(): void
    {
        $this->postJson('/studio/live/claim?token='.self::TOKEN, [])->assertStatus(422);
    }

    public function test_claim_is_refused_without_admin_or_token(): void
    {
        $this->postJson('/studio/live/claim', ['engineId' => 'engine-x'])->assertUnauthorized();
    }
}
