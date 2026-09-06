<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/shows')->assertRedirect('/admin/login');
        $this->get('/admin/ai-personas')->assertRedirect('/admin/login');
        $this->get('/admin/episodes')->assertRedirect('/admin/login');
    }

    public function test_authenticated_non_admin_users_get_a_403(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/shows')->assertForbidden();
        $this->actingAs($user)->get('/admin/episodes/create')->assertForbidden();
    }

    public function test_admin_users_reach_the_resources(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/shows')->assertOk();
        $this->actingAs($admin)->get('/admin/ai-personas')->assertOk();
        $this->actingAs($admin)->get('/admin/episodes')->assertOk();
    }
}
