<?php

namespace Database\Seeders;

use App\Models\DashboardLayout;
use App\Models\Division;
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
        $this->call(UnitDivisionSeeder::class);

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

        // Akun demo hanya untuk env local (guard eksplisit di caller).
        if (app()->environment('local')) {
            $this->seedDemoAccounts();
        }
    }

    /**
     * Akun demo non-admin + divisi, HANYA di environment lokal.
     *
     * Tujuannya smoke test manual: staf butuh division_id untuk mengunggah
     * dataset (kolom division_id NOT NULL). Setiap akun staf demo ditempatkan
     * pada sub-bidang organisasi yang memiliki data sendiri.
     * Password semua akun demo sama,
     * dari env DEMO_USER_PASSWORD (default 'password') — jangan pernah
     * mengaktifkan APP_ENV=local di server publik dengan akun ini ada.
     */
    private function seedDemoAccounts(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $demoPassword = env('DEMO_USER_PASSWORD', 'password');

        $pengusahaan = Division::where('slug', 'perencanaan-pengusahaan')->firstOrFail();
        $dataAset = Division::where('slug', 'managemen-aset')
            ->orWhere('slug', 'data-aset')
            ->firstOrFail();

        $demoUsers = [
            ['staf@dashboard.local', 'Staf Perencanaan Pengusahaan', Role::STAFF_DIVISI, $pengusahaan->id],
            ['staf.aset@dashboard.local', 'Staf Managemen Aset', Role::STAFF_DIVISI, $dataAset->id],
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

        if ($dataAset->slug === 'managemen-aset') {
            DashboardLayout::updateOrCreate(
                ['layout_key' => 'staff_divisi:managemen-aset'],
                [
                    'role_slug' => Role::STAFF_DIVISI,
                    'division_id' => $dataAset->id,
                    'widgets' => [
                        ['key' => 'dataset-status', 'cols' => 3],
                        ['key' => 'aset-upt-chart', 'cols' => 3],
                    ],
                ],
            );
        }

        if ($this->command) {
            $this->command->info('Akun demo (env local, password dari DEMO_USER_PASSWORD, default "password"):');
            $this->command->line('  staf@dashboard.local (Staf Perencanaan Pengusahaan)');
            $this->command->line('  staf.aset@dashboard.local (Staf Managemen Aset)');
            $this->command->line('  editor@dashboard.local (Editor) / management@dashboard.local (Management)');
        }
    }
}
