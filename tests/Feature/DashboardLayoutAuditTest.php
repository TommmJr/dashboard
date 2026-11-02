<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DashboardLayout;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);
    }

    public function test_updating_dashboard_layout_records_audit_log_entry(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        $this->actingAs($editor)->putJson(route('dashboard.layout.update'), [
            'role_slug' => Role::MANAGEMENT,
            'layout_key' => Role::MANAGEMENT,
            'widgets' => ['kpi-summary', 'trend-chart'],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $editor->id,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => (new DashboardLayout)->getMorphClass(),
        ]);
    }

    public function test_resetting_dashboard_layout_records_delete_audit_log_entry(): void
    {
        $editor = User::factory()->withRole(Role::EDITOR_DASHBOARD)->create();

        DashboardLayout::create([
            'role_slug' => Role::MANAGEMENT,
            'layout_key' => Role::MANAGEMENT,
            'widgets' => ['kpi-summary'],
            'updated_by' => $editor->id,
        ]);

        $this->actingAs($editor)->deleteJson(route('dashboard.layout.reset'), [
            'role_slug' => Role::MANAGEMENT,
            'layout_key' => Role::MANAGEMENT,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $editor->id,
            'action' => AuditLog::ACTION_DELETE,
            'auditable_type' => (new DashboardLayout)->getMorphClass(),
        ]);
    }
}
