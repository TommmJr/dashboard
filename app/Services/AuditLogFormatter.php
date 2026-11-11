<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Formatter audit log agar bisa dibaca orang awam (bukan JSON mentah).
 *
 * Dipakai oleh view audit-logs dan widget recent-audit: label kolom
 * diterjemahkan ke Indonesia, nilai di-humanize (tanggal d/m/Y, ukuran
 * KB/MB, laporan validasi diringkas), dan entri UPDATE dirender sebagai
 * pasangan perubahan "lama -> baru" per kolom.
 *
 * Kelas ini murni presentasi: TIDAK memfilter/menyembunyikan entri.
 * Otorisasi & scoping tetap di backend (AuditLogController + Gate).
 */
class AuditLogFormatter
{
    /**
     * Key yang tidak pernah boleh ditampilkan di UI audit.
     *
     * @var list<string>
     */
    public const SENSITIVE_KEYS = ['password', 'remember_token', 'api_token', 'api_key'];

    /**
     * Label kolom (bahasa Indonesia) untuk field yang dikenal.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'status' => 'Status',
        'error_message' => 'Pesan galat',
        'filename' => 'Berkas (internal)',
        'original_name' => 'Nama berkas',
        'mime_type' => 'Tipe berkas',
        'size' => 'Ukuran',
        'validation_report' => 'Laporan validasi',
        'division_id' => 'ID divisi',
        'user_id' => 'ID pengguna',
        'metric_name' => 'Nama metrik',
        'value' => 'Nilai',
        'period' => 'Periode',
        'unit_id' => 'ID pengunggah',
        'dataset_id' => 'ID dataset',
        'name' => 'Nama',
        'email' => 'Email',
        'role_id' => 'ID peran',
        'description' => 'Deskripsi',
        'slug' => 'Slug',
        'rows_imported' => 'Baris diimpor',
        'rows_skipped' => 'Baris ditolak',
        'created_at' => 'Waktu dibuat',
        'ip' => 'IP',
        'user_agent' => 'User Agent',
        'layout_key' => 'Kunci layout',
        'widgets' => 'Daftar widget',
        'role_slug' => 'Peran target',
        'org_units_count' => 'Unit organisasi',
        'indicators_count' => 'Indikator terstruktur',
        'observations_count' => 'Observasi terstruktur',
    ];

    /**
     * Label aksi (bahasa Indonesia) untuk badge di tabel.
     *
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        AuditLog::ACTION_LOGIN => 'Masuk',
        AuditLog::ACTION_LOGOUT => 'Keluar',
        AuditLog::ACTION_LOGIN_FAILED => 'Gagal Masuk',
        AuditLog::ACTION_CREATE => 'Tambah',
        AuditLog::ACTION_UPDATE => 'Ubah',
        AuditLog::ACTION_DELETE => 'Hapus',
        AuditLog::ACTION_IMPORT => 'Impor',
    ];

    /**
     * Label objek (auditable_type) yang mudah dibaca.
     *
     * @var array<string, string>
     */
    private const OBJECT_LABELS = [
        'Dataset' => 'Dataset',
        'PerformanceMetric' => 'Baris data kinerja',
        'User' => 'User',
        'Division' => 'Divisi',
        'Role' => 'Peran',
        'Permission' => 'Permission',
        'DashboardLayout' => 'Tata letak dashboard',
        'PerformanceObservation' => 'Observasi kinerja terstruktur',
        'Indicator' => 'Indikator kinerja',
        'OrgUnit' => 'Unit organisasi',
    ];

    /**
     * Baris perubahan untuk SATU entri audit, siap dirender view.
     *
     * Bentuk tiap baris: ['label' => string, 'old' => ?string, 'new' => ?string].
     * - UPDATE : pasangan old -> new per kolom yang berubah.
     * - CREATE/IMPORT : hanya new.
     * - DELETE : hanya old.
     * - LOGIN/LOGOUT : [] (tidak ada nilai).
     *
     * @return list<array{label: string, old: string|null, new: string|null}>
     */
    public static function rows(AuditLog $log): array
    {
        $old = static::clean($log->old_values);
        $new = static::clean($log->new_values);

        $keys = array_keys($new + $old);

        $rows = [];

        foreach ($keys as $key) {
            $label = static::fieldLabel((string) $key);
            $oldValue = array_key_exists($key, $old) ? static::humanize((string) $key, $old[$key]) : null;
            $newValue = array_key_exists($key, $new) ? static::humanize((string) $key, $new[$key]) : null;

            if ($oldValue === $newValue) {
                continue;
            }

            $rows[] = ['label' => $label, 'old' => $oldValue, 'new' => $newValue];
        }

        return $rows;
    }

    /**
     * Buang key sensitif + timestamp internal dari nilai audit.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    public static function clean(?array $values): array
    {
        return collect($values ?? [])
            ->except(static::SENSITIVE_KEYS)
            ->except(['updated_at'])
            ->all();
    }

    public static function fieldLabel(string $key): string
    {
        return static::FIELD_LABELS[$key] ?? Str::headline($key);
    }

    public static function actionLabel(string $action): string
    {
        return static::ACTION_LABELS[$action] ?? $action;
    }

    /**
     * Label objek: "Baris data kinerja #12", atau "Aktivitas akun" untuk
     * entri tanpa auditable (LOGIN/LOGOUT).
     */
    public static function objectLabel(AuditLog $log): string
    {
        if ($log->auditable_type === null) {
            return 'Aktivitas akun';
        }

        $base = class_basename($log->auditable_type);

        return (static::OBJECT_LABELS[$base] ?? Str::headline($base)).' #'.$log->auditable_id;
    }

    /**
     * Label nama pengguna yang dapat dibaca di UI tabel dan widget.
     * Untuk login gagal tanpa user terdaftar, tampilkan identitas email/username percobaan.
     */
    public static function userName(AuditLog $log): string
    {
        $user = $log->relationLoaded('user')
            ? $log->getRelation('user')
            : ($log->user_id !== null ? $log->user : null);

        if ($user?->name) {
            return $user->name;
        }

        if ($log->action === AuditLog::ACTION_LOGIN_FAILED) {
            $identity = $log->new_values['email'] ?? $log->new_values['username'] ?? null;
            if (is_string($identity) && $identity !== '') {
                return $identity;
            }

            return 'Tamu / Anonim';
        }

        if ($log->user_id !== null) {
            return 'Pengguna telah dihapus';
        }

        return '-';
    }

    /**
     * Ubah satu nilai mentah menjadi teks yang enak dibaca.
     */
    public static function humanize(string $key, mixed $value): string
    {
        if ($value === null) {
            return '(kosong)';
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if (is_array($value)) {
            return static::humanizeArray($key, $value);
        }

        if (is_string($value) && in_array($key, ['widgets', 'validation_report'], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return static::humanizeArray($key, $decoded);
            }
        }

        if ($key === 'size' && is_numeric($value)) {
            return static::humanizeBytes((float) $value);
        }

        if (is_string($value)) {
            return static::humanizeDate($value) ?? $value;
        }

        return (string) $value;
    }

    /**
     * Ringkas array (validation_report, dsb.) jadi satu kalimat.
     *
     * @param  array<string, mixed>  $value
     */
    private static function humanizeArray(string $key, array $value): string
    {
        if ($key === 'widgets') {
            return count($value).' widget terkonfigurasi';
        }

        if ($key === 'validation_report') {
            $ok = $value['ok'] ?? 0;
            $warning = $value['warning'] ?? 0;
            $fatal = $value['fatal'] ?? 0;

            return "{$ok} baris valid, {$warning} peringatan, {$fatal} ditolak";
        }

        return 'Isian ('.count($value).' item)';
    }

    private static function humanizeBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    }

    /**
     * Format string tanggal ISO (Y-m-d atau datetime) ke d/m/Y[ H:i].
     * Null = bukan tanggal, kembalikan apa adanya.
     */
    private static function humanizeDate(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->format('d/m/Y');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value)) {
            return Carbon::parse($value)->format('d/m/Y H:i');
        }

        return null;
    }
}
