<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_badge_and_logout_live_in_sidebar_footer(): void
    {
        $user = User::factory()->withRole(Role::MANAGEMENT)->create(['name' => 'Budi Santoso']);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="sidebar-user"', $html);
        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'));
        $this->assertStringContainsString('aria-label="Keluar"', $html);
        $this->assertStringNotContainsString('PLN UIT JBT Internal System', $html);
        $this->assertMatchesRegularExpression('/id="sidebar-user".*Budi Santoso.*action="'.preg_quote(route('logout'), '/').'"/s', $html);
    }

    public function test_header_shows_breadcrumb_instead_of_duplicate_title(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $html = $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="header-breadcrumb"', $html);
        $this->assertMatchesRegularExpression('/id="header-breadcrumb".*Beranda.*aria-current="page"[^>]*>Audit Log</s', $html);
        $this->assertSame(1, preg_match_all('/<h1[^>]*>\s*Audit Log\s*<\/h1>/', $html));
        $this->assertStringNotContainsString('<p class="text-sm font-bold text-primary">', $html);
    }
}
