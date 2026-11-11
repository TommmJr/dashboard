<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // Test that the root URL redirects to the login page
    public function test_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    // Test that the login page can be rendered successfully
    public function test_login_page_can_be_rendered(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in');
    }

    // Login dengan kredensial benar -> redirect ke dashboard & user terautentikasi.
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    // Fallback route: pengunjung tanpa login diarahkan ke halaman login.
    public function test_alamat_tidak_dikenal_diarahkan_ke_login_saat_guest(): void
    {
        $this->get('/halaman-tidak-ada')->assertRedirect(route('login'));
    }

    // Fallback route: user yang sudah login tidak dilempar balik ke login.
    public function test_alamat_tidak_dikenal_diarahkan_ke_dashboard_saat_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/halaman-tidak-ada')
            ->assertRedirect(route('dashboard'));
    }

    // Test that a user cannot log in with invalid credentials
    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_failed_login_attempt_is_recorded_in_audit_log_without_password(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'super-secret-wrong-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_LOGIN_FAILED,
            'user_id' => $user->id,
            'auditable_type' => null,
            'auditable_id' => null,
        ]);

        $log = AuditLog::where('action', AuditLog::ACTION_LOGIN_FAILED)
            ->where('user_id', $user->id)
            ->sole();

        $this->assertSame($user->email, $log->new_values['email']);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertStringNotContainsString('super-secret-wrong-password', json_encode($log->new_values));
    }

    // Test that the dashboard requires authentication
    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // Test that an authenticated user can view the dashboard
    public function test_authenticated_user_can_view_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($user->name);
    }

    // Test that an authenticated user can log out successfully
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // Rate limit login: 5 percobaan gagal per email+IP, percobaan ke-6 -> 429.
    public function test_login_diblokir_setelah_lima_percobaan_gagal(): void
    {
        $user = User::factory()->create(['email' => 'limit@example.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    // Rate limit dihitung per kombinasi email+IP, bukan per IP saja.
    public function test_rate_limit_terpisah_per_email_pada_ip_yang_sama(): void
    {
        User::factory()->create(['email' => 'kena-limit@example.test']);
        $lain = User::factory()->create(['email' => 'aman@example.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'kena-limit@example.test',
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'email' => 'aman@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
    }

    // Throttle berjalan sebelum controller: password benar pun tetap 429
    // dan user tidak jadi terautentikasi.
    public function test_password_benar_tetap_diblokir_setelah_limit(): void
    {
        $user = User::factory()->create(['email' => 'benar@example.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(429);

        $this->assertGuest();
    }
}
