<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder utama untuk mengisi data awal aplikasi.
 * Dijalankan dengan: php artisan db:seed
 *
 * Urutan: seed RBAC dulu (roles + permissions), lalu buat akun admin
 * pertama sebagai akun login dengan role Admin (semua permission).
 *
 * Password admin TIDAK di-hardcode: baca dari env ADMIN_DEFAULT_PASSWORD
 * (untuk development/CI). Jika env tidak di-set, generate string random
 * yang ditampilkan di console output seeder, sehingga tidak ada kredensial
 * default yang bisa ditebak di production (audit #4).
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Jalankan proses seeding.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Tentukan password admin:
        // - Prioritas 1: env ADMIN_DEFAULT_PASSWORD (untuk dev/CI, eksplisit).
        // - Prioritas 2: random string yang di-generate & dicetak ke console.
        $envPassword = env('ADMIN_DEFAULT_PASSWORD');
        $generatedPassword = false;

        if (is_string($envPassword) && $envPassword !== '') {
            $password = $envPassword;
        } else {
            // 24 karakter alfanumerik: cukup kuat untuk akun awal,
            // tapi tetap ter-print sehingga operator bisa login.
            $password = Str::random(24);
            $generatedPassword = true;
        }

        // firstOrCreate supaya db:seed berulang tidak gagal (email unique).
        $admin = User::firstOrCreate(
            ['email' => 'admin@dashboard.local'],
            [
                'name' => 'Admin',
                'password' => $password,
            ],
        );

        if (! $admin->hasRole(Role::ADMIN)) {
            $admin->role()->associate(Role::where('slug', Role::ADMIN)->firstOrFail())->save();
        }

        // Surface kredensial ke operator jika password baru di-generate.
        // Hanya ditampilkan saat seeding pertama (user baru dibuat).
        if ($generatedPassword && $admin->wasRecentlyCreated) {
            $this->command->warn('--- AKUN ADMIN BARU DIBUAT ---');
            $this->command->info('Email    : admin@dashboard.local');
            $this->command->info('Password : '.$password);
            $this->command->warn('SIMPAN password ini sekarang. Tidak akan ditampilkan lagi.');
            $this->command->warn('Untuk produksi, set ADMIN_DEFAULT_PASSWORD di .env dan jalankan ulang.');
            $this->command->newLine();
        }
    }
}
