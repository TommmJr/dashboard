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
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->division = Division::where('slug', 'perencanaan-pengusahaan')->sole();
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
        $this->withHeader('User-Agent', 'phpunit-audit-probe')
            ->post(route('login.store'), [
                'email' => $this->staff->email,
                'password' => 'password',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_LOGIN,
            'user_id' => $this->staff->id,
        ]);

        // Provenance audit: ip dan user_agent diambil dari request aktif,
        // bukan dibiarkan kosong (dipakai untuk menelusuri asal aksi).
        $login = AuditLog::where('action', AuditLog::ACTION_LOGIN)
            ->where('user_id', $this->staff->id)
            ->sole();
        $this->assertSame('127.0.0.1', $login->ip);
        $this->assertSame('phpunit-audit-probe', $login->user_agent);

        $this->actingAs($this->staff)->post(route('logout'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_LOGOUT,
            'user_id' => $this->staff->id,
        ]);
    }

    public function test_failed_login_with_existing_user_records_audit_log_without_password(): void
    {
        $response = $this->withHeader('User-Agent', 'failed-login-probe')
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => $this->staff->email,
                'password' => 'wrong-secret-password',
            ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');

        $log = AuditLog::where('action', AuditLog::ACTION_LOGIN_FAILED)
            ->where('user_id', $this->staff->id)
            ->sole();

        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('failed-login-probe', $log->user_agent);

        $this->assertSame($this->staff->email, $log->new_values['email']);
        $this->assertSame('127.0.0.1', $log->new_values['ip']);
        $this->assertSame('failed-login-probe', $log->new_values['user_agent']);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertStringNotContainsString('wrong-secret-password', json_encode($log->new_values));

        // Entri harus lolos dari scope cleanAuditTrail
        $this->assertTrue(
            AuditLog::cleanAuditTrail()->where('id', $log->id)->exists(),
            'Audit log login gagal harus lolos query cleanAuditTrail'
        );
    }

    public function test_failed_login_with_non_existent_user_records_audit_log_without_password(): void
    {
        $response = $this->withHeader('User-Agent', 'failed-nonexistent-probe')
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'ghost.user@pln.co.id',
                'password' => 'another-wrong-password',
            ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');

        $log = AuditLog::where('action', AuditLog::ACTION_LOGIN_FAILED)
            ->whereNull('user_id')
            ->sole();

        $this->assertNull($log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertNull($log->user_id);
        $this->assertSame('ghost.user@pln.co.id', $log->new_values['email']);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertStringNotContainsString('another-wrong-password', json_encode($log->new_values));

        // Verifikasi bahwa admin dapat melihat dan memfilter entri ini di index audit log
        $admin = User::factory()->withRole(Role::ADMIN)->create();
        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['action' => AuditLog::ACTION_LOGIN_FAILED]))
            ->assertOk()
            ->assertSee('Gagal Masuk')
            ->assertSee('ghost.user@pln.co.id');
    }

    public function test_entri_audit_tidak_bisa_diubah_lewat_instance(): void
    {
        $log = AuditLog::record(AuditLog::ACTION_LOGIN);

        $thrown = false;
        try {
            $log->update(['action' => 'X']);
        } catch (LogicException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'instance update harus melempar LogicException');
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => AuditLog::ACTION_LOGIN,
        ]);
    }

    public function test_entri_audit_tidak_bisa_dihapus_lewat_instance(): void
    {
        $log = AuditLog::record(AuditLog::ACTION_LOGIN);

        $thrown = false;
        try {
            $log->delete();
        } catch (LogicException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'instance delete harus melempar LogicException');
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    /**
     * Jalur massal ditutup oleh AuditLogBuilder (P0-4d).
     *
     * Builder bawaan Eloquent menulis lewat toBase() sehingga event
     * `updating` tidak menyala untuk operasi bulk; override di builder itulah
     * yang menutupnya. Test ini sebelumnya canary yang membuktikan bypass —
     * sekarang menuntut penolakan.
     */
    public function test_mass_update_ditolak(): void
    {
        $log = AuditLog::record(AuditLog::ACTION_LOGIN);

        $thrown = false;
        try {
            AuditLog::query()->update(['action' => 'MASS_TAMPERED']);
        } catch (LogicException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'mass update harus melempar LogicException');
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => AuditLog::ACTION_LOGIN,
        ]);
    }

    /**
     * Jalur massal ditutup oleh AuditLogBuilder (P0-4d).
     *
     * Sama seperti mass update: override delete() di builder menolak
     * penghapusan massal, sehingga entri audit tidak bisa dimusnahkan.
     */
    public function test_mass_delete_ditolak(): void
    {
        $log = AuditLog::record(AuditLog::ACTION_LOGIN);

        $thrown = false;
        try {
            AuditLog::query()->delete();
        } catch (LogicException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'mass delete harus melempar LogicException');
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    /**
     * Protokol lengkap jalur massal yang menulis tanpa model event.
     *
     * Dipetakan dari Eloquent\Builder: setiap method di bawah memakai
     * toBase()/$this->query langsung. forceDelete() khusus, karena memakai
     * $this->query->delete() dan TIDAK lewat toBase() — sempat lolos saat
     * override hanya menutup update()/delete().
     *
     * @return array<string, array{0: \Closure}>
     */
    public static function jalurMassalProvider(): array
    {
        return [
            'update' => [fn () => AuditLog::query()->update(['action' => 'x'])],
            'where update' => [fn () => AuditLog::where('action', AuditLog::ACTION_LOGIN)->update(['action' => 'x'])],
            'upsert' => [fn () => AuditLog::query()->upsert([['action' => 'x']], ['id'], ['action'])],
            'touch' => [fn () => AuditLog::query()->touch('created_at')],
            'increment' => [fn () => AuditLog::query()->increment('id')],
            'decrement' => [fn () => AuditLog::query()->decrement('id')],
            'incrementEach' => [fn () => AuditLog::query()->incrementEach(['id' => 1])],
            'decrementEach' => [fn () => AuditLog::query()->decrementEach(['id' => 1])],
            'delete' => [fn () => AuditLog::query()->delete()],
            'where delete' => [fn () => AuditLog::where('action', AuditLog::ACTION_LOGIN)->delete()],
            'forceDelete' => [fn () => AuditLog::query()->forceDelete()],
        ];
    }

    /**
     * @param  \Closure(): mixed  $call
     */
    #[DataProvider('jalurMassalProvider')]
    public function test_semua_jalur_massal_ditolak(\Closure $call): void
    {
        $log = AuditLog::record(AuditLog::ACTION_LOGIN);

        $thrown = false;
        $other = null;
        try {
            $call();
        } catch (LogicException) {
            $thrown = true;
        } catch (\Throwable $e) {
            $other = $e::class.': '.$e->getMessage();
        }

        $this->assertTrue(
            $thrown,
            $other === null
                ? 'jalur massal harus melempar LogicException'
                : 'melempar exception lain yang tidak diharapkan -> '.$other
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => AuditLog::ACTION_LOGIN,
        ]);
    }

    /**
     * Guard massal TIDAK boleh ikut memblokir jalur tulis yang sah.
     * Append-only berarti entri boleh ditambah, hanya tidak boleh diubah.
     */
    public function test_jalur_tulis_sah_tetap_jalan(): void
    {
        // record() + create() tetap boleh menambah entri.
        $a = AuditLog::record(AuditLog::ACTION_LOGIN);
        $b = AuditLog::create(['action' => AuditLog::ACTION_LOGOUT]);

        $this->assertTrue($a->exists);
        $this->assertTrue($b->exists);

        // Baca tetap jalan.
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_LOGIN)->count());
        $this->assertNotNull(AuditLog::query()->first());
    }

    public function test_halaman_audit_menampilkan_detail_entri(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        AuditLog::create([
            'action' => AuditLog::ACTION_LOGIN,
            'user_id' => $admin->id,
            'ip' => '10.1.2.3',
            'user_agent' => 'ua-detail-probe',
        ]);

        $this->actingAs($admin)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('ua-detail-probe')
            ->assertSee('10.1.2.3');
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

    public function test_audit_log_has_compact_filter(): void
    {
        $admin = User::factory()->withRole(Role::ADMIN)->create();

        $html = $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Riwayat perubahan data', $html);
        $this->assertMatchesRegularExpression('/id="audit-filter".*name="action".*name="from".*name="to"/s', $html);
        $this->assertStringContainsString('Semua aksi', $html);
    }
}
