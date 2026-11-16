<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetSheetsSyncService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EndpointRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const THROTTLE_MESSAGE = 'Terlalu banyak permintaan. Silakan coba beberapa saat lagi.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_named_rate_limiters_are_registered_for_login_and_sensitive_endpoints(): void
    {
        $this->assertNotNull(RateLimiter::limiter('login'));
        $this->assertNotNull(RateLimiter::limiter('chart-api'));
        $this->assertNotNull(RateLimiter::limiter('layout-mutation'));
        $this->assertNotNull(RateLimiter::limiter('dataset-heavy'));
    }

    public function test_chart_api_enforces_60_per_minute_shared_limit_per_user_and_returns_indonesian_429(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();
        $otherUser = User::factory()->withRole(Role::ADMIN)->create();

        for ($i = 0; $i < 59; $i++) {
            $this->actingAs($management)
                ->getJson(route('performance.data'))
                ->assertOk();
        }

        $sixtieth = $this->actingAs($management)
            ->getJson(route('api.dashboard.custom-chart-data', [
                'metric' => 'nko_score',
                'chart_type' => 'bar',
            ]));

        $sixtieth->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $throttledPerformance = $this->actingAs($management)
            ->getJson(route('performance.data'));

        $throttledPerformance->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);

        $throttledCustomChart = $this->actingAs($management)
            ->getJson(route('api.dashboard.custom-chart-data', [
                'metric' => 'nko_score',
                'chart_type' => 'bar',
            ]));

        $throttledCustomChart->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);

        $this->actingAs($otherUser)
            ->getJson(route('performance.data'))
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '59');
    }

    public function test_layout_mutation_enforces_20_per_minute_shared_limit_without_throttling_builder_get(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $otherEditor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $builderResponse = $this->actingAs($editor)->get(route('dashboard.builder'));
        $builderResponse->assertOk();
        $this->assertNull($builderResponse->headers->get('X-RateLimit-Limit'));

        $payload = [
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['nko-donut', 'kpi-summary'],
        ];

        for ($i = 0; $i < 19; $i++) {
            $this->actingAs($editor)
                ->putJson(route('dashboard.layout.update'), $payload)
                ->assertOk();
        }

        $twentieth = $this->actingAs($editor)
            ->deleteJson(route('dashboard.layout.reset'), [
                'role_slug' => Role::STAFF_DIVISI,
                'division_id' => $division->id,
            ]);

        $twentieth->assertOk()
            ->assertHeader('X-RateLimit-Limit', '20')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $throttledJson = $this->actingAs($editor)
            ->putJson(route('dashboard.layout.update'), $payload);

        $throttledJson->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);

        $throttledWeb = $this->actingAs($editor)
            ->put(route('dashboard.layout.update'), $payload);

        $throttledWeb->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee(self::THROTTLE_MESSAGE);

        $this->actingAs($otherEditor)
            ->putJson(route('dashboard.layout.update'), $payload)
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '19');
    }

    public function test_layout_mutation_denies_unauthorized_user_with_403_then_throttles_excess_flood(): void
    {
        $staff = User::factory()->withRole(Role::STAFF_DIVISI)->create();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($staff)
                ->putJson(route('dashboard.layout.update'), [
                    'role_slug' => Role::ADMIN,
                    'widgets' => ['admin-summary'],
                ])
                ->assertForbidden();
        }

        $this->actingAs($staff)
            ->putJson(route('dashboard.layout.update'), [
                'role_slug' => Role::ADMIN,
                'widgets' => ['admin-summary'],
            ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);
    }

    public function test_dataset_heavy_enforces_10_per_minute_shared_limit_across_heavy_post_routes_only(): void
    {
        $this->seed(UnitDivisionSeeder::class);

        $admin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);
        $otherAdmin = User::factory()->withRole(Role::ADMIN)->create(['division_id' => null]);

        $this->mock(AssetSheetsSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->times(3)
                ->andReturn([
                    'dataset' => null,
                    'period' => '2026-08-31',
                ]);
        });

        $createPage = $this->actingAs($admin)->get(route('datasets.create'));
        $createPage->assertOk();
        $this->assertNull($createPage->headers->get('X-RateLimit-Limit'));

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)
                ->postJson(route('datasets.store'), [])
                ->assertStatus(422);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)
                ->postJson(route('datasets.asset-upload'), [])
                ->assertStatus(422);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($admin)
                ->postJson(route('datasets.asset-sync'), [])
                ->assertOk();
        }

        for ($i = 0; $i < 2; $i++) {
            $response = $this->actingAs($admin)
                ->postJson(route('datasets.kpi-import'), []);

            $response->assertStatus(422);
            if ($i === 1) {
                $response->assertHeader('X-RateLimit-Limit', '10')
                    ->assertHeader('X-RateLimit-Remaining', '0');
            }
        }

        foreach (['datasets.store', 'datasets.asset-upload', 'datasets.asset-sync', 'datasets.kpi-import'] as $routeName) {
            $this->actingAs($admin)
                ->postJson(route($routeName), [])
                ->assertStatus(429)
                ->assertHeader('Retry-After')
                ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);
        }

        $this->actingAs($admin)
            ->post(route('datasets.asset-sync'), [])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee(self::THROTTLE_MESSAGE);

        $this->actingAs($otherAdmin)
            ->postJson(route('datasets.asset-sync'), [])
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '9');
    }

    public function test_authenticated_key_tracks_user_across_ip_changes_and_resets_after_one_minute(): void
    {
        $division = Division::factory()->create(['slug' => 'perencanaan-pengusahaan']);
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();
        $payload = [
            'role_slug' => Role::STAFF_DIVISI,
            'division_id' => $division->id,
            'widgets' => ['nko-donut', 'kpi-summary'],
        ];

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($editor)
                ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->putJson(route('dashboard.layout.update'), $payload)
                ->assertOk();
        }

        $this->actingAs($editor)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->putJson(route('dashboard.layout.update'), $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);

        $this->travel(61)->seconds();

        $this->actingAs($editor)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->putJson(route('dashboard.layout.update'), $payload)
            ->assertOk();
    }

    public function test_guest_and_unauthorized_requests_enforce_auth_and_fail_closed_with_rate_limit_protection(): void
    {
        $management = User::factory()->withRole(Role::MANAGEMENT)->create();

        $this->getJson(route('performance.data'))->assertUnauthorized();
        $this->post(route('datasets.store'), [])->assertRedirect(route('login'));

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($management)
                ->postJson(route('datasets.store'), [])
                ->assertForbidden();
        }

        $this->actingAs($management)
            ->postJson(route('datasets.store'), [])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => self::THROTTLE_MESSAGE]);
    }
}
