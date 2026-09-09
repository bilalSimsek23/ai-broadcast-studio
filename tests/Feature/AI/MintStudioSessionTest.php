<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Providers\Fake\FakeRealtimeVoiceProvider;
use App\AI\Realtime\MintStudioSession;
use Tests\TestCase;

class MintStudioSessionTest extends TestCase
{
    private function mint(): MintStudioSession
    {
        return $this->app->make(MintStudioSession::class);
    }

    public function test_the_default_session_length_is_twenty_minutes(): void
    {
        // config/ai.php default, nothing overridden.
        $this->assertSame(1200, ($this->mint())()->sessionMaxSeconds);
    }

    public function test_it_honours_a_configured_length_including_a_longer_rehearsal(): void
    {
        config()->set('ai.realtime.session_max_seconds', 2400);

        $this->assertSame(2400, ($this->mint())()->sessionMaxSeconds);
    }

    public function test_it_clamps_an_out_of_range_configured_length(): void
    {
        config()->set('ai.realtime.session_max_seconds', 5);
        $this->assertSame(30, ($this->mint())()->sessionMaxSeconds);

        config()->set('ai.realtime.session_max_seconds', 999_999);
        $this->assertSame(3600, ($this->mint())()->sessionMaxSeconds);
    }

    public function test_it_falls_back_to_twenty_minutes_when_the_config_value_is_unusable(): void
    {
        config()->set('ai.realtime.session_max_seconds', 'not-a-number');

        $this->assertSame(1200, ($this->mint())()->sessionMaxSeconds);
    }

    public function test_a_zero_configured_default_means_no_session_limit(): void
    {
        config()->set('ai.realtime.session_max_seconds', 0);

        $this->assertSame(0, ($this->mint())()->sessionMaxSeconds);
    }

    public function test_the_director_can_pick_an_allow_listed_session_duration(): void
    {
        // 2400 and 0 are keys in config('ai.realtime.session_durations').
        $this->assertSame(2400, ($this->mint())(null, null, 2400)->sessionMaxSeconds);
        $this->assertSame(0, ($this->mint())(null, null, 0)->sessionMaxSeconds);
    }

    public function test_a_duration_not_on_the_allow_list_falls_back_to_the_config_default(): void
    {
        config()->set('ai.realtime.session_max_seconds', 1200);

        $this->assertSame(1200, ($this->mint())(null, null, 99)->sessionMaxSeconds);
    }

    public function test_it_forwards_the_configured_studio_brief_and_webrtc_url(): void
    {
        config()->set('ai.realtime.instructions', 'Stüdyo brifingi.');
        config()->set('ai.realtime.webrtc_url', 'https://example.test/realtime/calls');

        $session = ($this->mint())();

        $this->assertSame('https://example.test/realtime/calls', $session->webrtcUrl);
        $this->assertSame(
            'Stüdyo brifingi.',
            $this->app->make(FakeRealtimeVoiceProvider::class)->lastCall()?->instructions,
        );
    }

    public function test_no_requested_voice_leaves_the_provider_default(): void
    {
        ($this->mint())();

        $this->assertNull($this->app->make(FakeRealtimeVoiceProvider::class)->lastCall()?->voiceOverride);
    }

    public function test_it_forwards_an_allow_listed_requested_voice_as_an_override(): void
    {
        ($this->mint())('marin');

        $this->assertSame('marin', $this->app->make(FakeRealtimeVoiceProvider::class)->lastCall()?->voiceOverride);
    }

    public function test_it_ignores_a_voice_that_is_not_on_the_allow_list(): void
    {
        ($this->mint())('totally-not-a-voice');

        $this->assertNull($this->app->make(FakeRealtimeVoiceProvider::class)->lastCall()?->voiceOverride);
    }

    public function test_it_passes_getusermedia_constraints_through_from_config(): void
    {
        // Defaults (config/ai.php): all three on.
        $this->assertSame(
            ['echoCancellation' => true, 'noiseSuppression' => true, 'autoGainControl' => true],
            ($this->mint())()->audioConstraints,
        );

        config()->set('ai.realtime.audio.constraints.autoGainControl', false);

        $this->assertSame(
            ['echoCancellation' => true, 'noiseSuppression' => true, 'autoGainControl' => false],
            ($this->mint())()->audioConstraints,
        );
    }
}
