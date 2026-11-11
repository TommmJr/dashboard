<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\AuditLogFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Unit test AuditLogFormatter: label kolom/aksi/objek berbahasa Indonesia,
 * humanisasi nilai (tanggal d/m/Y, ukuran KB/MB, laporan validasi diringkas),
 * pasangan perubahan lama -> baru, dan eksklusi key sensitif.
 *
 * Murni unit — model AuditLog dibangun tanpa DB (atribut di-set sebagai
 * array; cast 'array' model menangani encode/decode seperti dari database).
 */
class AuditLogFormatterTest extends TestCase
{
    private function log(array $attributes): AuditLog
    {
        return new AuditLog([
            'action' => $attributes['action'] ?? AuditLog::ACTION_UPDATE,
            'auditable_type' => $attributes['auditable_type'] ?? null,
            'auditable_id' => $attributes['auditable_id'] ?? null,
            'old_values' => $attributes['old_values'] ?? null,
            'new_values' => $attributes['new_values'] ?? null,
        ]);
    }

    public function test_label_field_dikenal_berbahasa_indonesia_dan_tak_dikenal_di_headline(): void
    {
        $this->assertSame('Nama berkas', AuditLogFormatter::fieldLabel('original_name'));
        $this->assertSame('Baris diimpor', AuditLogFormatter::fieldLabel('rows_imported'));
        $this->assertSame('Foo Bar', AuditLogFormatter::fieldLabel('foo_bar'));
        $this->assertSame('Unit organisasi', AuditLogFormatter::fieldLabel('org_units_count'));
        $this->assertSame('Indikator terstruktur', AuditLogFormatter::fieldLabel('indicators_count'));
        $this->assertSame('Observasi terstruktur', AuditLogFormatter::fieldLabel('observations_count'));
        $this->assertSame('User Agent', AuditLogFormatter::fieldLabel('user_agent'));
    }

    public function test_label_objek_model_terstruktur_memiliki_nama_baku(): void
    {
        $observation = $this->log([
            'auditable_type' => 'App\\Models\\PerformanceObservation',
            'auditable_id' => 10,
        ]);
        $this->assertSame('Observasi kinerja terstruktur #10', AuditLogFormatter::objectLabel($observation));

        $indicator = $this->log([
            'auditable_type' => 'App\\Models\\Indicator',
            'auditable_id' => 20,
        ]);
        $this->assertSame('Indikator kinerja #20', AuditLogFormatter::objectLabel($indicator));

        $orgUnit = $this->log([
            'auditable_type' => 'App\\Models\\OrgUnit',
            'auditable_id' => 30,
        ]);
        $this->assertSame('Unit organisasi #30', AuditLogFormatter::objectLabel($orgUnit));
    }

    public function test_aksi_dan_objek_dilabeli_readable(): void
    {
        $this->assertSame('Impor', AuditLogFormatter::actionLabel(AuditLog::ACTION_IMPORT));
        $this->assertSame('Ubah', AuditLogFormatter::actionLabel(AuditLog::ACTION_UPDATE));
        $this->assertSame('Gagal Masuk', AuditLogFormatter::actionLabel(AuditLog::ACTION_LOGIN_FAILED));

        $dataset = $this->log([
            'auditable_type' => Dataset::class,
            'auditable_id' => 7,
        ]);
        $this->assertSame('Dataset #7', AuditLogFormatter::objectLabel($dataset));

        $login = $this->log(['action' => AuditLog::ACTION_LOGIN]);
        $this->assertSame('Aktivitas akun', AuditLogFormatter::objectLabel($login));

        $loginFailed = $this->log(['action' => AuditLog::ACTION_LOGIN_FAILED]);
        $this->assertSame('Aktivitas akun', AuditLogFormatter::objectLabel($loginFailed));
    }

    public function test_tanggal_diformat_dm_y_dan_ukuran_jadi_kb(): void
    {
        $this->assertSame('15/01/2026', AuditLogFormatter::humanize('period', '2026-01-15'));
        $this->assertSame('15/01/2026 09:30', AuditLogFormatter::humanize('created_at', '2026-01-15T09:30:00+00:00'));
        $this->assertSame('2,0 KB', AuditLogFormatter::humanize('size', 2048));
        $this->assertSame('(kosong)', AuditLogFormatter::humanize('error_message', null));
    }

    public function test_update_menghasilkan_pasangan_lama_baru(): void
    {
        $log = $this->log([
            'old_values' => ['status' => Dataset::STATUS_READY],
            'new_values' => ['status' => Dataset::STATUS_ARCHIVED],
        ]);

        $rows = AuditLogFormatter::rows($log);

        $this->assertCount(1, $rows);
        $this->assertSame('Status', $rows[0]['label']);
        $this->assertSame(Dataset::STATUS_READY, $rows[0]['old']);
        $this->assertSame(Dataset::STATUS_ARCHIVED, $rows[0]['new']);
    }

    public function test_create_hanya_nilai_baru_dan_delete_hanya_nilai_lama(): void
    {
        $create = $this->log([
            'action' => AuditLog::ACTION_CREATE,
            'new_values' => ['metric_name' => 'Pendapatan', 'value' => 100],
        ]);
        $rows = AuditLogFormatter::rows($create);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['old']);
        }

        $delete = $this->log([
            'action' => AuditLog::ACTION_DELETE,
            'old_values' => ['metric_name' => 'Pendapatan'],
        ]);
        $rows = AuditLogFormatter::rows($delete);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['new']);
    }

    public function test_key_sensitif_dibuang_dari_tampilan(): void
    {
        $log = $this->log([
            'new_values' => ['password' => 'rahasia-hash', 'name' => 'Budi'],
        ]);

        $rows = AuditLogFormatter::rows($log);

        $this->assertCount(1, $rows);
        $this->assertSame('Nama', $rows[0]['label']);
        $this->assertStringNotContainsString('rahasia-hash', json_encode($rows));
    }

    public function test_laporan_validasi_diringkas_satu_kalimat(): void
    {
        $summary = AuditLogFormatter::humanize('validation_report', [
            'ok' => 10, 'warning' => 2, 'fatal' => 1, 'issues' => ['x'],
        ]);

        $this->assertSame('10 baris valid, 2 peringatan, 1 ditolak', $summary);
    }

    public function test_login_failed_menghasilkan_baris_identitas_dan_metadata(): void
    {
        $log = $this->log([
            'action' => AuditLog::ACTION_LOGIN_FAILED,
            'new_values' => [
                'email' => 'operator@pln.co.id',
                'ip' => '10.20.30.40',
                'user_agent' => 'Mozilla/5.0',
            ],
        ]);

        $rows = AuditLogFormatter::rows($log);

        $this->assertCount(3, $rows);
        $this->assertSame('Email', $rows[0]['label']);
        $this->assertSame('operator@pln.co.id', $rows[0]['new']);
        $this->assertSame('IP', $rows[1]['label']);
        $this->assertSame('10.20.30.40', $rows[1]['new']);
        $this->assertSame('User Agent', $rows[2]['label']);
        $this->assertSame('Mozilla/5.0', $rows[2]['new']);
    }
}
