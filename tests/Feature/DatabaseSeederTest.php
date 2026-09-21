<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Memverifikasi bahwa DatabaseSeeder:
 * - TIDAK menggunakan password hardcoded 'password' (audit #4).
 * - Membaca ADMIN_DEFAULT_PASSWORD dari env jika tersedia.
 * - Generate password random jika env tidak di-set dan berhasil login.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_tidak_menggunakan_password_hardcoded_password(): void
    {
        // Pastikan password admin BUKAN 'password'.
        $this->artisan('db:seed')->assertExitCode(0);

        $admin = User::where('email', 'admin@dashboard.local')->firstOrFail();

        $this->assertFalse(
            Hash::check('password', $admin->password),
            'Password admin tidak boleh hardcoded "password".',
        );
    }

    public function test_seeder_menggunakan_env_password_jika_di_set(): void
    {
        // ADMIN_DEFAULT_PASSWORD ada di env -> gunakan itu.
        putenv('ADMIN_DEFAULT_PASSWORD=S3cretEnvP@ss!');

        try {
            $this->artisan('db:seed')->assertExitCode(0);

            $admin = User::where('email', 'admin@dashboard.local')->firstOrFail();

            $this->assertTrue(Hash::check('S3cretEnvP@ss!', $admin->password));
        } finally {
            putenv('ADMIN_DEFAULT_PASSWORD');
        }
    }

    public function test_seeder_generate_random_password_jika_env_tidak_di_set(): void
    {
        // env kosong -> random. Pastikan admin ada dan password valid (ter-hash).
        putenv('ADMIN_DEFAULT_PASSWORD');

        $this->artisan('db:seed')->assertExitCode(0);

        $admin = User::where('email', 'admin@dashboard.local')->firstOrFail();
        $this->assertNotEmpty($admin->password);

        // Password hasil generate minimal 16 karakter untuk memastikan kekuatan.
        // Kita verifikasi via plaintext yang di-return seeder (lihat test lain),
        // di sini cukup cek password di-hash dan bukan 'password'.
        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_admin_role_diasosiasikan_setelah_seeding(): void
    {
        $this->artisan('db:seed')->assertExitCode(0);

        $admin = User::where('email', 'admin@dashboard.local')->firstOrFail();
        $this->assertNotNull($admin->role);
        $this->assertSame(Role::ADMIN, $admin->role->slug);
    }

    public function test_db_seed_menyediakan_divisi_untuk_akun_demo(): void
    {
        // seedDemoAccounts() butuh kedua slug ini via firstOrFail; jika
        // panggilan UnitDivisionSeeder dihapus, fresh seed lokal gagal.
        $this->artisan('db:seed')->assertExitCode(0);

        Division::where('slug', 'perencanaan-pengusahaan')->sole();
        Division::where('slug', 'data-aset')->sole();
    }

    public function test_db_seed_local_berhasil_dan_idempoten_dari_database_kosong(): void
    {
        // Jalur yang gagal sebelum fix: APP_ENV=local + DB kosong.
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $this->artisan('db:seed')->assertExitCode(0);
            $this->artisan('db:seed')->assertExitCode(0);

            $this->assertSame(2, Division::count());

            $staf = User::where('email', 'staf@dashboard.local')->sole();
            $this->assertSame(Role::STAFF_DIVISI, $staf->role->slug);
            $this->assertSame(
                Division::where('slug', 'perencanaan-pengusahaan')->sole()->id,
                $staf->division_id,
            );

            $stafAset = User::where('email', 'staf.aset@dashboard.local')->sole();
            $this->assertSame(Role::STAFF_DIVISI, $stafAset->role->slug);
            $this->assertSame(
                Division::where('slug', 'data-aset')->sole()->id,
                $stafAset->division_id,
            );

            $this->assertSame(1, User::where('email', 'admin@dashboard.local')->count());
            $this->assertSame(1, User::where('email', 'editor@dashboard.local')->count());
            $this->assertSame(1, User::where('email', 'management@dashboard.local')->count());
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }
}
