<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * StudioBroadcastAccess gates the endpoints a REMOTE broadcast screen needs:
 * an authenticated admin OR the shared STUDIO_LIVE_ACCESS_TOKEN passes.
 * `GET /studio/live/control` is the simplest such endpoint to probe.
 */
class StudioBroadcastAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('cache.default', 'database');
    }

    public function test_an_admin_always_passes(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/studio/live/control')->assertOk();
    }

    public function test_a_guest_with_no_token_is_rejected(): void
    {
        config()->set('ai.realtime.public_access_token', 'the-secret');

        $this->getJson('/studio/live/control')->assertUnauthorized();
    }

    public function test_the_matching_token_passes_via_header_or_query(): void
    {
        config()->set('ai.realtime.public_access_token', 'the-secret');

        $this->getJson('/studio/live/control', ['X-Studio-Token' => 'the-secret'])->assertOk();
        $this->getJson('/studio/live/control?token=the-secret')->assertOk();
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        config()->set('ai.realtime.public_access_token', 'the-secret');

        $this->getJson('/studio/live/control?token=nope')->assertUnauthorized();
    }

    public function test_with_no_configured_token_the_token_path_is_disabled(): void
    {
        config()->set('ai.realtime.public_access_token', null);

        // Even a "token" cannot open it — only an admin.
        $this->getJson('/studio/live/control?token=anything')->assertUnauthorized();
        $this->getJson('/studio/live/control')->assertUnauthorized();
    }

    public function test_a_non_admin_user_without_a_token_is_forbidden(): void
    {
        config()->set('ai.realtime.public_access_token', 'the-secret');
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/studio/live/control')->assertForbidden();
    }
}
