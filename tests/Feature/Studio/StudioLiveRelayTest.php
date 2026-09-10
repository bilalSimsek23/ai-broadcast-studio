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

    public function test_the_screen_posts_state_and_the_console_reads_it(): void
    {
        // Screen posts with the token only.
        $this->postJson('/studio/live/state?token='.self::TOKEN, [
            'connected' => true,
            'muted' => false,
            'remainingSeconds' => 540,
            'status' => 'Yayında',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->admin();
        $this->getJson('/studio/live/state')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('remainingSeconds', 540)
            ->assertJsonPath('status', 'Yayında')
            ->assertJsonPath('alive', true);
    }

    public function test_state_read_is_admin_only(): void
    {
        $this->getJson('/studio/live/state?token='.self::TOKEN)->assertUnauthorized();
    }

    public function test_a_missing_state_reports_not_alive(): void
    {
        $this->admin();

        $this->getJson('/studio/live/state')->assertOk()->assertJsonPath('alive', false);
    }
}
