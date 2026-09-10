<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioLivePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_broadcast_screen_is_public_no_login_prompt(): void
    {
        // Opened as a display / capture source (Remix, OBS, spare monitor) — a
        // guest must NOT be bounced to a login. The page holds no credential
        // and does nothing without operator commands.
        $this->get('/studio/live')
            ->assertOk()
            ->assertSee('id="orb"', escape: false)
            ->assertDontSee('sk-')
            ->assertDontSee('OPENAI_API_KEY');
    }

    public function test_the_broadcast_output_is_only_the_orb_no_controls_no_text(): void
    {
        $response = $this->get('/studio/live')->assertOk();

        // The orb canvas is the whole visible page.
        $response->assertSee('id="orb"', escape: false);

        // Operator controls belong on the Filament "Canlı Yayın Kontrolü"
        // page, never on the broadcast output: no buttons, no control DOM.
        $response->assertDontSee('<button', escape: false);
        $response->assertDontSee('id="controls"', escape: false);
        $response->assertDontSee('id="connect"', escape: false);
        $response->assertDontSee('id="hangup"', escape: false);
        $response->assertDontSee('id="timer"', escape: false);
        $response->assertDontSee('id="status"', escape: false);
        $response->assertDontSee('Yayın görünümü');
        $response->assertDontSee('Görüşmeyi bitir');

        // Same-browser fast path (BroadcastChannel) AND a server command/state
        // relay so the screen can run remotely / in vMix.
        $response->assertSee("new BroadcastChannel('studio-live')", escape: false);
        $response->assertSee('pollControl', escape: false);
        $response->assertSee('X-Studio-Token', escape: false);
        $response->assertSee("URLSearchParams(location.search).get('token')", escape: false);

        // A broadcast still-image layer exists, shown/hidden from the control
        // page only (no controls on the output itself).
        $response->assertSee('id="still"', escape: false);
        $response->assertSee("d.type === 'image'", escape: false);

        // Session length still comes from the backend, not a literal; 0 = no limit.
        $response->assertSee('startTimer(s.session_max_seconds)', escape: false);
        $response->assertSee('max_seconds', escape: false);
        $response->assertSee('Number(s.session_max_seconds) > 0', escape: false);
        $response->assertDontSee('|| 600', escape: false);

        // Device application happens here (WebRTC owner), driven by the control page.
        $response->assertSee('{ exact: inputDeviceId }', escape: false);
        $response->assertSee('setSinkId', escape: false);

        // No transcript, no credential leak.
        $response->assertDontSee('transcript');
        $response->assertDontSee('OPENAI_API_KEY');
        $response->assertDontSee('sk-');
    }
}
