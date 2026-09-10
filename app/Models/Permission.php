<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Model Permission - permission granular sistem (dot notation).
 *
 * Daftar permission TIDAK di-hardcode di tempat lain: semua definisi
 * bersumber dari PERMISSIONS di sini (single source of truth), dipakai
 * oleh seeder, registrasi gate, dan test. Naming mengikuti FR-02 di PRD.
 * Permission AI (ai.query dll.) sengaja belum masuk karena AI adalah
 * Phase 2-3, di luar scope MVP.
 */
class Permission extends Model
{
    /**
     * Katalog permission: name => deskripsi singkat.
     *
     * Sumber: section FR-02 di PRD IBS + matriks hak akses di User Roles.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        // Manajemen user (khusus Admin).
        'user.view' => 'Melihat daftar user',
        'user.create' => 'Menambah user baru',
        'user.update' => 'Mengubah data user',
        'user.delete' => 'Menghapus user',

        // Dataset / data mentah.
        'dataset.view' => 'Melihat dataset dan isinya',
        'dataset.import' => 'Mengunggah file Excel/CSV sebagai dataset baru',
        'dataset.update' => 'Edit sederhana data raw langsung di aplikasi',
        'dataset.delete' => 'Menghapus dataset',

        // Dashboard & visualisasi.
        'dashboard.view' => 'Melihat dashboard kinerja',
        'dashboard.create' => 'Membuat dashboard baru',
        'dashboard.update' => 'Mengubah struktur dashboard (widget, layout)',
        'dashboard.delete' => 'Menghapus dashboard',
        'dashboard.publish' => 'Mempublikasikan dashboard ke pembaca',

        // Lain-lain.
        'audit.view' => 'Melihat riwayat perubahan data (audit log); tanpa audit.view_all hanya entri perubahan data (dataset & metrik)',
        'audit.view_all' => 'Melihat SELURUH audit log termasuk aktivitas non-data (login, perubahan user)',
        'report.export' => 'Mengunduh laporan (PDF/Excel)',
    ];

    protected $fillable = ['name', 'description'];

    /**
     * Role-role yang memiliki permission ini.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Daftar nama permission (kunci dari PERMISSIONS).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * Cek apakah sebuah nama ability terdaftar sebagai permission resmi.
     */
    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::PERMISSIONS);
    }
}
