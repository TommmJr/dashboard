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

        $this->seedDemoAccounts();
    }

    /**
     * Akun demo non-admin + divisi, HANYA di environment lokal.
     *
     * Tujuannya smoke test manual: staf butuh division_id untuk mengunggah
     * dataset (kolom division_id NOT NULL). Password semua akun demo sama,
     * dari env DEMO_USER_PASSWORD (default 'password') — jangan pernah
     * mengaktifkan APP_ENV=local di server publik dengan akun ini ada.
     */
    private function seedDemoAccounts(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $demoPassword = env('DEMO_USER_PASSWORD', 'password');

        $divisions = [
            ['name' => 'Perencanaan Pengusahaan', 'slug' => 'perencanaan-pengusahaan'],
            ['name' => 'Perencanaan Sistem', 'slug' => 'perencanaan-sistem'],
        ];

        foreach ($divisions as $division) {
            Division::firstOrCreate(['slug' => $division['slug']], $division);
        }

        $pengusahaan = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $sistem = Division::where('slug', 'perencanaan-sistem')->firstOrFail();

        $demoUsers = [
            ['staf@dashboard.local', 'Staf Pengusahaan', Role::STAFF_DIVISI, $pengusahaan->id],
            ['staf.sistem@dashboard.local', 'Staf Sistem', Role::STAFF_DIVISI, $sistem->id],
            ['editor@dashboard.local', 'Editor Dashboard', Role::EDITOR_DASHBOARD, null],
            ['management@dashboard.local', 'Management', Role::MANAGEMENT, null],
        ];

        foreach ($demoUsers as [$email, $name, $roleSlug, $divisionId]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $demoPassword, 'division_id' => $divisionId],
            );

            if (! $user->hasRole($roleSlug)) {
                $user->role()->associate(Role::where('slug', $roleSlug)->firstOrFail())->save();
            }
        }

        if ($this->command) {
            $this->command->info('Akun demo (env local, password dari DEMO_USER_PASSWORD, default "password"):');
            $this->command->line('  staf@dashboard.local / staf.sistem@dashboard.local (Staf Divisi)');
            $this->command->line('  editor@dashboard.local (Editor) / management@dashboard.local (Management)');
        }
    }
}
