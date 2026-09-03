<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder utama untuk mengisi data awal aplikasi.
 * Dijalankan dengan: php artisan db:seed
 *
 * Urutan: seed RBAC dulu (roles + permissions), lalu buat akun admin
 * pertama sebagai akun login dengan role Admin (semua permission).
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Jalankan proses seeding.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Akun admin pertama. Ganti password setelah login lewat aplikasi.
        // firstOrCreate supaya db:seed berulang tidak gagal (email unique).
        $admin = User::firstOrCreate(
            ['email' => 'admin@dashboard.local'],
            [
                'name' => 'Admin',
                'password' => 'password',
            ],
        );

        if (! $admin->hasRole(Role::ADMIN)) {
            $admin->role()->associate(Role::where('slug', Role::ADMIN)->firstOrFail())->save();
        }
    }
}
