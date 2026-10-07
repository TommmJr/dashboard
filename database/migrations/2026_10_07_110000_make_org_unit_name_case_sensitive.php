<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nama unit case-sensitive (keputusan bisnis 2026-10-07: kapitalisasi
     * mengikuti data; TJBT vs tjbt = dua unit berbeda).
     *
     * MySQL default utf8mb4_unicode_ci menggabungkan varian kapitalisasi
     * sehingga firstOrCreate() menyatukannya; utf8mb4_bin membuatnya
     * case-sensitive seperti SQLite (BINARY). Guard driver: SQLite sudah
     * case-sensitive dan tidak mengenal collation ini.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE org_units MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE org_units MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
