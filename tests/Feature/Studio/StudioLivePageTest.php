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

    public function test_an_admin_sees_the_voice_stage_with_no_transcript(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get('/studio/live')->assertOk();

        $response->assertSee('id="orb"', escape: false);
        $response->assertSee('Bağlan');
        $response->assertSee('Görüşmeyi bitir');
        $response->assertSee('/studio/live/session', escape: false);

        // Clean broadcast view toggle + the shared clean-shutdown path used on
        // both "Görüşmeyi bitir" and the session time limit expiring.
        $response->assertSee('Yayın görünümü');
        $response->assertSee('body.clean', escape: false);
        $response->assertSee('Süre doldu');

        // No hardcoded session length in the page — it comes from the backend.
        $response->assertSee('startTimer(s.session_max_seconds)', escape: false);
        $response->assertDontSee('|| 600', escape: false);

        // Rehearsal/transcript UI must not be present, and no credential leaks.
        $response->assertDontSee('transcript');
        $response->assertDontSee('OPENAI_API_KEY');
        $response->assertDontSee('sk-');
    }
}
