<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use LogicException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Audit log append-only: setiap aksi penting meninggalkan jejak dengan
 * actor yang benar; entri tidak dapat diubah/dihapus lewat model;
 * halaman audit hanya untuk pemegang audit.view.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UnitDivisionSeeder::class);

        $this->division = Division::where('slug', 'tjbt')->sole();
        $this->staff = User::factory()->withRole(Role::STAFF_DIVISI)->create([
            'division_id' => $this->division->id,
        ]);
    }

    private function uploadFile(User $user): Dataset
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue([1, 1], 'metric_name');
        $sheet->setCellValue([2, 1], 'value');
        $sheet->setCellValue([3, 1], 'period');
        $sheet->setCellValue([1, 2], 'Penjualan');
        $sheet->setCellValue([2, 2], '100');
        $sheet->setCellValue([3, 2], '2026-01-31');
        $path = tempnam(sys_get_temp_dir(), 'aud_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $file = new UploadedFile($path, 'laporan.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($user)->post(route('datasets.store'), ['file' => $file]);

        return Dataset::sole();
    }

    public function test_upload_mencatat_create_dan_import_dengan_actor_benar(): void
    {
        $dataset = $this->uploadFile($this->staff);

        $create = AuditLog::where('action', AuditLog::ACTION_CREATE)
            ->where('auditable_type', Dataset::class)
            ->where('auditable_id', $dataset->id)
            ->sole();
        $this->assertSame($this->staff->id, $create->user_id);

        $import = AuditLog::where('action', AuditLog::ACTION_IMPORT)
            ->where('auditable_id', $dataset->id)
            ->sole();
        $this->assertSame($this->staff->id, $import->user_id);
        $this->assertSame(1, $import->new_values['rows_imported']);
    }

    public function test_archive_mencatat_update_dengan_perubahan_status(): void
    {
        $dataset = Dataset::factory()->create(['division_id' => $this->division->id]);

        $this->actingAs($this->staff)->patch(route('datasets.archive', $dataset));

        $log = AuditLog::where('action', AuditLog::ACTION_UPDATE)
            ->where('auditable_type', Dataset::class)
            ->where('auditable_id', $dataset->id)
            ->sole();
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame(Dataset::STATUS_READY, $log->old_values['status']);
        $this->assertSame(Dataset::STATUS_ARCHIVED, $log->new_values['status']);
    }

    public function test_delete_mencatat_delete(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $dataset = Dataset::factory()->create(['division_id' => $this->division->id]);

        $this->actingAs($admin)->delete(route('datasets.destroy', $dataset));

        $log = AuditLog::where('action', AuditLog::ACTION_DELETE)
            ->where('auditable_id', $dataset->id)
            ->sole();
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_login_dan_logout_tercatat(): void
    {
        $this->post(route('login.store'), [
            'email' => $this->staff->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_LOGIN,
            'user_id' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)->post(route('logout'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_LOGOUT,
            'user_id' => $this->staff->id,
        ]);
    }

    public function test_entri_audit_tidak_bisa_diubah_atau_dihapus(): void
    {
        $log = AuditLog::record(AuditLog::ACTION_LOGIN);

        $this->expectException(LogicException::class);
        try {
            $log->update(['action' => 'X']);
        } finally {
            // Pastikan delete juga dilempar, dengan assertion terpisah.
        }

        $fresh = AuditLog::find($log->id);
        $thrown = false;
        try {
            $fresh->delete();
        } catch (LogicException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_halaman_audit_200_untuk_berhak_dan_403_untuk_staf(): void
    {
        foreach ([Role::ADMIN, Role::MANAGEMENT, Role::EDITOR_DASHBOARD] as $slug) {
            $user = User::factory()->withRole($slug)->create();
            $this->actingAs($user)->get(route('audit-logs.index'))->assertOk();
        }

        // Staf divisi tidak punya audit.view.
        $this->actingAs($this->staff)->get(route('audit-logs.index'))->assertForbidden();
    }
}
