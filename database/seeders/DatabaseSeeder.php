<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder utama untuk mengisi data awal aplikasi.
 * Dijalankan dengan: php artisan db:seed
 *
 * Saat ini hanya membuat 1 user admin sebagai akun login pertama.
 * Password di-hash otomatis oleh Laravel lewat cast 'hashed' di model User.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Jalankan proses seeding.
     */
    public function run(): void
    {
        // Akun admin pertama. Ganti password setelah login lewat aplikasi.
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@dashboard.local',
            'password' => 'password',
        ]);
    }
}
