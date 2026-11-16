<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    // Verifikasi semua security header hadir di response halaman publik (login).
    public function test_security_headers_present_on_guest_page(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('X-Powered-By');
    }

    // Verifikasi security header hadir di halaman yang butuh auth.
    public function test_security_headers_present_on_authenticated_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    // CSP harus ada dan berisi direktif minimal yang dibutuhkan.
    public function test_csp_header_contains_required_directives(): void
    {
        $response = $this->get(route('login'));

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    // CSP harus memblokir CDN residual & 'unsafe-inline' pada script-src, sambil menjaga 'unsafe-eval' untuk Alpine v3.
    public function test_csp_removes_cdn_and_unsafe_inline_from_script_src(): void
    {
        $response = $this->get(route('login'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match('/(?:^|;\s*)script-src\s+([^;]+)/i', $csp, $matches);
        $scriptSrc = $matches[1] ?? '';

        $this->assertNotSame('', $scriptSrc);
        $this->assertStringContainsString("'self'", $scriptSrc);
        $this->assertStringContainsString("'unsafe-eval'", $scriptSrc);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=_-]+'/", $scriptSrc);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    }

    // CSP nonce harus unik per-request dan cocok dengan semua tag <script> executable pada halaman.
    public function test_csp_nonce_is_unique_per_request_and_matches_executable_scripts(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $response1 = $this->actingAs($management)->get(route('dashboard'))->assertOk();
        $csp1 = (string) $response1->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp1, $m1);
        $nonce1 = $m1[1] ?? '';
        $this->assertNotSame('', $nonce1);

        $response2 = $this->actingAs($management)->get(route('dashboard'))->assertOk();
        $csp2 = (string) $response2->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp2, $m2);
        $nonce2 = $m2[1] ?? '';
        $this->assertNotSame('', $nonce2);
        $this->assertNotSame($nonce1, $nonce2);

        $html = $response1->getContent();
        preg_match_all('/<script\b([^>]*)>/i', $html, $scriptTags);
        $this->assertNotEmpty($scriptTags[1]);

        foreach ($scriptTags[1] as $attrs) {
            if (preg_match('/\btype\s*=\s*["\']application\/json["\']/i', $attrs) === 1) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$nonce1.'"', $attrs);
        }
    }

    // Halaman login publik juga wajib menyematkan nonce unik per-request pada tag <script>.
    public function test_csp_nonce_matches_executable_scripts_on_guest_login_page(): void
    {
        $loginResponse1 = $this->get(route('login'))->assertOk();
        $csp1 = (string) $loginResponse1->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp1, $m1);
        $nonce1 = $m1[1] ?? '';
        $this->assertNotSame('', $nonce1);

        $loginResponse2 = $this->get(route('login'))->assertOk();
        $csp2 = (string) $loginResponse2->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp2, $m2);
        $nonce2 = $m2[1] ?? '';
        $this->assertNotSame('', $nonce2);
        $this->assertNotSame($nonce1, $nonce2);

        preg_match_all('/<script\b([^>]*)>/i', $loginResponse1->getContent(), $loginScriptTags);
        $this->assertNotEmpty($loginScriptTags[1]);
        foreach ($loginScriptTags[1] as $attrs) {
            if (preg_match('/\btype\s*=\s*["\']application\/json["\']/i', $attrs) === 1) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$nonce1.'"', $attrs);
        }
    }

    // Tidak boleh ada atribut inline event handler (onsubmit/onchange/onclick) di HTML ter-render.
    public function test_rendered_pages_have_no_inline_event_handler_attributes(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $asetDivision = Division::where('slug', 'managemen-aset')->sole();
        $stafAset = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $asetDivision->id,
        ]);

        $pages = [
            [null, route('login')],
            [$admin, route('dashboard')],
            [$admin, route('datasets.index')],
            [$admin, route('users.index')],
            [$admin, route('divisions.index')],
            [$management, route('dashboard')],
            [$management, route('dashboard.kpi-detail')],
            [$stafAset, route('dashboard')],
            [$stafAset, route('dashboard.asset-detail')],
        ];

        foreach ($pages as [$actor, $url]) {
            $request = $actor ? $this->actingAs($actor) : $this;
            $response = $request->get($url)->assertOk();
            $html = $response->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/\son(?:click|change|submit|load|error|input|keydown|keyup|focus|blur)\s*=/i',
                $html,
                "Ditemukan inline event handler pada {$url}",
            );
        }
    }

    // Default config/session.php wajib mengenkripsi session secara default namun tetap kompatibel dengan HTTP lokal.
    public function test_session_encryption_defaults_to_true_and_keeps_http_compatibility(): void
    {
        $prevEnv = $_ENV['SESSION_ENCRYPT'] ?? null;
        $prevServer = $_SERVER['SESSION_ENCRYPT'] ?? null;
        $prevPutenv = getenv('SESSION_ENCRYPT');

        try {
            unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
            putenv('SESSION_ENCRYPT');

            $sessionConfig = require config_path('session.php');

            $this->assertTrue($sessionConfig['encrypt']);
            $this->assertNull($sessionConfig['secure']);
        } finally {
            if ($prevEnv !== null) {
                $_ENV['SESSION_ENCRYPT'] = $prevEnv;
            }
            if ($prevServer !== null) {
                $_SERVER['SESSION_ENCRYPT'] = $prevServer;
            }
            if ($prevPutenv !== false) {
                putenv('SESSION_ENCRYPT='.$prevPutenv);
            }
        }

        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertNotFalse($envExample);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $envExample);

        config(['session.encrypt' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)
            ->withSession(['probe_key' => 'rahasia-uji'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('probe_key', 'rahasia-uji');

        // Pastikan HSTS / upgrade-insecure-requests tidak dipasang paksa agar localhost HTTP tetap kompatibel.
        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');
    }

    // Pastikan Vite facade masih bisa memakai nonce saat test memanggilnya.
    public function test_csp_allows_unsafe_eval_for_alpine(): void
    {
        $response = $this->get(route('login'));

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'unsafe-eval'", $csp);
        $this->assertNotNull(Vite::cspNonce());
    }

    // Redirect response (misal / -> /login) juga harus punya security headers.
    public function test_security_headers_present_on_redirect_response(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
