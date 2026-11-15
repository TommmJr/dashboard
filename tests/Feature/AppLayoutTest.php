<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Division;
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

    public function test_login_page_renders_centered_illustrated_portal(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('IBS UIT JBT')
            ->assertSee('Masuk ke Ruang Kerja')
            ->assertSee('id="login-portal-card"', false)
            ->assertSee('id="login-vector-illustration"', false)
            ->assertSee('action="'.route('login.store').'"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false)
            ->assertDontSee('Pelaporan Visual Terintegrasi UIT JBT')
            ->assertDontSee('19.447')
            ->assertDontSee('13.259,4')
            ->assertDontSee('50.112,0')
            ->assertDontSee('Tren Realisasi')
            ->assertDontSee('Peringkat 8 UPT Kanonis')
            ->assertDontSee('Sign in — IBS UIT JBT')
            ->assertDontSee('Login - IBS UIT JBT')
            ->assertDontSee('Integrated Business System — PT PLN')
            ->assertDontSee('—')
            ->assertDontSee('&mdash;', false)
            ->assertDontSee('&ndash;', false);
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

    public function test_clean_white_sidebar_and_topbar_context_badge_render_across_roles(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $asetDiv = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDiv->id]);

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $adminResponse->assertOk()
            ->assertSee('id="main-sidebar"', false)
            ->assertSee('bg-white text-slate-700', false)
            ->assertDontSee('bg-primary text-white', false)
            ->assertSee('id="sidebar-user"', false)
            ->assertSee('id="topbar-context-badge"', false)
            ->assertSee('Admin');

        $staffResponse = $this->actingAs($stafAset)->get(route('dashboard'));
        $staffResponse->assertOk()
            ->assertSee('id="main-sidebar"', false)
            ->assertSee('bg-white text-slate-700', false)
            ->assertSee('id="sidebar-user"', false)
            ->assertSee('id="topbar-context-badge"', false)
            ->assertSee('Managemen Aset');
    }

    public function test_sidebar_enforces_cross_role_rbac_isolation(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create(['division_id' => null]);

        $asetDiv = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDiv->id]);

        $pengusahaanDiv = Division::factory()->create(['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan']);
        $stafPengusahaan = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $pengusahaanDiv->id]);

        // Admin: tata kelola + builder + dataset, 0 division deep-dive links
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('users.index'), false)
            ->assertSee(route('divisions.index'), false)
            ->assertSee(route('audit-logs.index'), false)
            ->assertSee(route('dashboard.builder'), false)
            ->assertSee(route('datasets.index'), false)
            ->assertSee(route('datasets.create'), false)
            ->assertDontSee('id="sidebar-kpi-detail-link"', false)
            ->assertDontSee('id="sidebar-kpi-analytics-link"', false)
            ->assertDontSee('id="sidebar-asset-detail-link"', false)
            ->assertDontSee('id="sidebar-asset-mva-link"', false)
            ->assertDontSee('id="sidebar-asset-transmisi-link"', false)
            ->assertDontSee('id="sidebar-asset-tower-link"', false);

        // Staf Managemen Aset: 4 asset exploration links + datasets, no KPI/admin/builder links
        $this->actingAs($stafAset)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-asset-detail-link"', false)
            ->assertSee('id="sidebar-asset-mva-link"', false)
            ->assertSee('id="sidebar-asset-transmisi-link"', false)
            ->assertSee('id="sidebar-asset-tower-link"', false)
            ->assertSee(route('datasets.index'), false)
            ->assertSee(route('datasets.create'), false)
            ->assertDontSee('id="sidebar-kpi-detail-link"', false)
            ->assertDontSee('id="sidebar-kpi-analytics-link"', false)
            ->assertDontSee(route('dashboard.builder'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('divisions.index'), false)
            ->assertDontSee(route('audit-logs.index'), false);

        // Staf Perencanaan Pengusahaan: KPI detail + analytics + datasets, no asset/admin/builder links
        $this->actingAs($stafPengusahaan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-detail-link"', false)
            ->assertSee('id="sidebar-kpi-analytics-link"', false)
            ->assertSee(route('datasets.index'), false)
            ->assertSee(route('datasets.create'), false)
            ->assertDontSee('id="sidebar-asset-detail-link"', false)
            ->assertDontSee(route('dashboard.builder'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('divisions.index'), false)
            ->assertDontSee(route('audit-logs.index'), false);

        // Management: KPI detail + analytics + audit log + dataset view, no upload/builder/users
        $this->actingAs($management)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-detail-link"', false)
            ->assertSee('id="sidebar-kpi-analytics-link"', false)
            ->assertSee(route('audit-logs.index'), false)
            ->assertSee(route('datasets.index'), false)
            ->assertDontSee(route('datasets.create'), false)
            ->assertDontSee(route('dashboard.builder'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('divisions.index'), false)
            ->assertDontSee('id="sidebar-asset-detail-link"', false);

        // Editor Dashboard: KPI detail + builder + dataset view + data-only audit log, no upload/analytics/users/asset
        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-kpi-detail-link"', false)
            ->assertSee(route('dashboard.builder'), false)
            ->assertSee(route('datasets.index'), false)
            ->assertSee(route('audit-logs.index'), false)
            ->assertDontSee(route('datasets.create'), false)
            ->assertDontSee('id="sidebar-kpi-analytics-link"', false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('divisions.index'), false)
            ->assertDontSee('id="sidebar-asset-detail-link"', false);
    }

    public function test_flash_notification_dirender_tepat_satu_kali_tanpa_duplikasi_lintas_halaman(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $asetDiv = Division::factory()->create(['name' => 'Managemen Aset', 'slug' => 'managemen-aset']);
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create(['division_id' => $asetDiv->id]);
        $dataset = Dataset::factory()->create([
            'division_id' => $asetDiv->id,
            'user_id' => $stafAset->id,
        ]);

        $sessionPayload = [
            'success' => 'Pesan Sukses Tunggal SSOT',
            'error' => 'Pesan Error Tunggal SSOT',
        ];

        $adminUrls = [
            route('datasets.index'),
            route('datasets.create'),
            route('datasets.show', $dataset),
            route('users.index'),
            route('divisions.index'),
        ];

        foreach ($adminUrls as $url) {
            $html = $this->actingAs($admin)
                ->withSession($sessionPayload)
                ->get($url)
                ->assertOk()
                ->getContent();

            $this->assertSame(1, substr_count($html, 'Pesan Sukses Tunggal SSOT'), "Duplikasi success flash pada {$url}");
            $this->assertSame(1, substr_count($html, 'Pesan Error Tunggal SSOT'), "Duplikasi error flash pada {$url}");
            $this->assertStringContainsString('role="status"', $html);
            $this->assertStringContainsString('role="alert"', $html);
            $this->assertStringContainsString('aria-label="Tutup notifikasi"', $html);
            $this->assertStringContainsString('@click="dismissed = true"', $html);
        }

        $staffHtml = $this->actingAs($stafAset)
            ->withSession($sessionPayload)
            ->get(route('datasets.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($staffHtml, 'Pesan Sukses Tunggal SSOT'));
        $this->assertSame(1, substr_count($staffHtml, 'Pesan Error Tunggal SSOT'));
        $this->assertStringContainsString('role="status"', $staffHtml);
        $this->assertStringContainsString('role="alert"', $staffHtml);
        $this->assertStringContainsString('aria-label="Tutup notifikasi"', $staffHtml);
    }

    public function test_executive_glass_navbar_renders_luxury_breadcrumb_pill_and_status_badge(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $response = $this->actingAs($admin)->get(route('dashboard.builder'));

        $response->assertOk()
            ->assertSee('overflow-x-clip', false)
            ->assertSee('h-14', false)
            ->assertSee('bg-white/85', false)
            ->assertSee('backdrop-blur-md', false)
            ->assertSee('shadow-[0_1px_2px_0_rgba(15,23,42,0.03)]', false)
            ->assertSee('bg-[#e6f4f6] text-[#0b6175]', false)
            ->assertSee('id="header-breadcrumb"', false)
            ->assertSee('flex min-w-0 items-center gap-2 text-[13px] text-slate-500', false)
            ->assertSee('id="topbar-context-badge"', false)
            ->assertSee('rounded-full border border-slate-200/60 bg-slate-50/70 px-2.5 py-1 text-[11px] font-medium tracking-tight text-slate-600', false)
            ->assertSee('h-1.5 w-1.5 shrink-0 rounded-full bg-[#0b6175]', false)
            ->assertDontSee('rounded-lg border border-slate-200/75 bg-slate-50/85', false)
            ->assertDontSee('animate-ping', false)
            ->assertDontSee('bg-slate-900 text-white shadow-xs', false)
            ->assertDontSee('font-mono', false);

        $html = $response->getContent();
        $this->assertSame(1, preg_match('/<header\b[^>]*>.*?<\/header>/s', $html, $headerMatch));
        $headerHtml = $headerMatch[0];

        $this->assertStringNotContainsString('>Dashboard<', $headerHtml);
        $this->assertMatchesRegularExpression('/id="header-breadcrumb".*Beranda.*aria-current="page"[^>]*>Dashboard Builder</s', $headerHtml);
        $this->assertSame(1, substr_count($headerHtml, 'Dashboard Builder'));
    }

    public function test_minimalist_navbar_omits_redundant_dashboard_label_across_routes(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $management = User::factory()->withRole(Role::MANAGEMENT)->create(['division_id' => null]);

        $dashboardHtml = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<header\b[^>]*>.*?<\/header>/s', $dashboardHtml, $dashHeaderMatch));
        $this->assertStringNotContainsString('>Dashboard<', $dashHeaderMatch[0]);
        $this->assertMatchesRegularExpression('/id="header-breadcrumb"[^>]*>.*aria-current="page"[^>]*>Beranda</s', $dashHeaderMatch[0]);

        $kpiHtml = $this->actingAs($management)->get(route('dashboard.kpi-detail'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<header\b[^>]*>.*?<\/header>/s', $kpiHtml, $kpiHeaderMatch));
        $this->assertStringNotContainsString('>Dashboard<', $kpiHeaderMatch[0]);
        $this->assertMatchesRegularExpression('/id="header-breadcrumb".*Beranda.*aria-current="page"[^>]*>Rincian Kinerja KPI</s', $kpiHeaderMatch[0]);
    }
}
