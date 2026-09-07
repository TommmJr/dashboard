<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom division_id ke performance_metrics.
 *
 * Latar belakang: tugas audit keamanan point #3 menemukan bahwa
 * PerformanceChartController::index() mengembalikan data SELURU divisi
 * tanpa scoping — user staf divisi A bisa melihat KPI divisi B.
 *
 * Dengan kolom ini, setiap metric memiliki division_id eksplisit
 * (diisi saat upload dari division_id uploader), dan controller
 * melakukan scoping: admin/management lihat semua, non-admin hanya
 * divisinya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->foreignId('division_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('divisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropColumn('division_id');
        });
    }
};
