<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioControlPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_the_panel_login(): void
    {
        $this->get('/admin/studio-control')->assertRedirect('/admin/login');
    }

    public function test_a_non_admin_cannot_open_the_studio_control(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/studio-control')->assertForbidden();
    }

    public function test_an_admin_sees_the_operator_console(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get('/admin/studio-control')->assertOk();

        // Section cards + operator info block
        $response->assertSee('Ses Yönlendirme');
        $response->assertSee('Canlı Oturum');
        $response->assertSee('Operatör Bilgisi');
        $response->assertSee('Yayın Ekranı');

        // Transport + mute controls
        $response->assertSee('BAĞLAN');
        $response->assertSee('GÖRÜŞMEYİ BİTİR');
        $response->assertSee('MİKROFONU SESSİZE AL');
        $response->assertSee('MİKROFONU AÇ');
        $response->assertSee('Yayın ekranını aç');

        // Device selectors + voice picker + refresh + status readouts
        $response->assertSee('AI Ses Girişi');
        $response->assertSee('AI Ses Çıkışı');
        $response->assertSee('AI Sesi');
        $response->assertSee('Cedar — erkek'); // default MALE voice, from config allow-list
        $response->assertSee('Marin — kadın');
        $response->assertSee('Cihazları Yenile');
        $response->assertSee('Yapay zekânın dinleyeceği ses kaynağı');
        $response->assertSee('Yapay zekâ sesinin gönderileceği çıkış');
        $response->assertSee('Kalan süre');
        $response->assertSee('Bağlantı');
        $response->assertSee('Mikrofon');

        // Rendered with Filament's own component classes (not raw utility soup),
        // so it is styled by Filament's shipped CSS without an app Tailwind build.
        $response->assertSee('fi-section', escape: false);
        $response->assertSee('fi-select-input', escape: false);
        $response->assertSee('fi-btn', escape: false);
        $response->assertDontSee('rounded-xl border border-gray-200', escape: false);
        $response->assertDontSee('block w-full rounded-lg border-gray-300', escape: false);

        // Same-origin BroadcastChannel bridge to /studio/live, browser-local
        // device persistence — no server relay, no server-side device config.
        $response->assertSee("new BroadcastChannel('studio-live')", escape: false);
        $response->assertSee('enumerateDevices', escape: false);
        $response->assertSee('localStorage', escape: false);

        // The unsupported-output fallback message is present.
        $response->assertSee('ses çıkışı seçimini desteklemiyor');

        // No credential ever reaches this page.
        $response->assertDontSee('OPENAI_API_KEY');
        $response->assertDontSee('client_secret');
        $response->assertDontSee('sk-');
    }
}
