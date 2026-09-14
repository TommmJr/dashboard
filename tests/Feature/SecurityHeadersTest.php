<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

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

    // CSP harus mengizinkan Alpine.js CDN untuk script.
    public function test_csp_allows_alpine_cdn(): void
    {
        $response = $this->get(route('login'));

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('cdn.jsdelivr.net', $csp);
    }

    // CSP harus mengizinkan 'unsafe-eval' — Alpine.js v3 butuh untuk
    // compile template expression (x-data, @mouseenter, :class $store).
    public function test_csp_allows_unsafe_eval_for_alpine(): void
    {
        $response = $this->get(route('login'));

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'unsafe-eval'", $csp);
    }

    // Redirect response (misal / -> /login) juga harus punya security headers.
    public function test_security_headers_present_on_redirect_response(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
