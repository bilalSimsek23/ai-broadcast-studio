<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioLivePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get('/studio/live')->assertRedirect('/admin/login');
    }

    public function test_an_authenticated_non_admin_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/studio/live')->assertForbidden();
    }

    public function test_the_broadcast_output_is_only_the_orb_no_controls_no_text(): void
    {
        $this->actingAs(User::factory()->admin()->create());

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

        // Controlled over a same-origin BroadcastChannel — no server relay.
        $response->assertSee("new BroadcastChannel('studio-live')", escape: false);

        // Session length still comes from the backend, not a literal.
        $response->assertSee('startTimer(s.session_max_seconds)', escape: false);
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
