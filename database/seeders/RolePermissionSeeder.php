<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder RBAC: mengisi permissions, 4 role, dan pivot mapping
 * sesuai matriks hak akses di dokumen [[User Roles]] (vault).
 *
 * Prinsip kunci dari keputusan mentor 2026-08-28:
 * - Lihat dashboard TRANSPARAN dalam Bidang Perencanaan -> semua role
 *   punya dashboard.view.
 * - Editor Dashboard (IT) TANPA hak tulis data apa pun -> tidak dapat
 *   dataset.import/dataset.update/dataset.delete (read-only mutlak).
 * - Staf Divisi hanya boleh upload/edit data untuk divisinya sendiri
 *   (pembatasan per-divisi ditegakkan Tahap 3 saat tabel divisions ada).
 * - Semua perubahan data kelak tercatat audit log (Tahap 9).
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Mapping role => permission, sesuai matriks User Roles section 5.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return [
            Role::ADMIN => Permission::names(),

            Role::MANAGEMENT => [
                'dashboard.view',
                'dataset.view',
                'audit.view',
                'audit.view_all',
            ],

            Role::EDITOR_DASHBOARD => [
                'dashboard.view',
                'dashboard.update',
                'dataset.view',
                'audit.view',
            ],

            Role::STAFF_DIVISI => [
                'dashboard.view',
                'dataset.view',
                'dataset.import',
                'dataset.update',
            ],
        ];
    }

    /**
     * Jalankan seeding roles + permissions.
     */
    public function run(): void
    {
        // Idempoten: firstOrCreate supaya db:seed berulang tidak error.
        $permissions = collect(Permission::PERMISSIONS)
            ->map(fn (string $description, string $name) => Permission::firstOrCreate(
                ['name' => $name],
                ['description' => $description],
            ))
            ->all();

        $roles = [
            [Role::ADMIN, 'Admin', 'Super admin sistem: kelola user, permission, dataset, dashboard, audit log'],
            [Role::MANAGEMENT, 'Management', 'Pimpinan: penonton utama dashboard dan KPI'],
            [Role::EDITOR_DASHBOARD, 'Editor Dashboard', 'Bidang teknis IT: mengelola visual dashboard, akses data read-only mutlak'],
            [Role::STAFF_DIVISI, 'Staf Divisi', 'Penyedia data untuk divisinya sendiri (unggah Excel/CSV + edit sederhana)'],
        ];

        foreach ($roles as [$slug, $name, $description]) {
            $role = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description],
            );

            // Sinkronkan permission sesuai matriks (sync, bukan attach:
            $role->permissions()->sync(
                Permission::whereIn('name', self::matrix()[$slug])->pluck('id'),
            );

        }
    }
}
