<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance periode pada observation terstruktur.
     *
     * Latar: `period` adalah periode EFEKTIF hasil import/form. Nilai itu tidak
     * selalu sama dengan periode yang tertulis di banner sheet ("S.D. FEBRUARI
     * 2025"). Supaya perbedaan itu tidak hilang, banner disimpan apa adanya di
     * `sheet_period` dan perbedaannya ditandai `period_conflict`.
     *
     * Dua kolom ini MURNI bukti/provenance:
     *
     * - `sheet_period` nullable: banyak sheet tidak punya banner periode, dan
     *   banner itu teks bebas, jadi TIDAK boleh dipaksa jadi tanggal.
     * - `period_conflict` default false: import TIDAK gagal hanya karena
     *   periode form berbeda dari banner. Konflik dicatat, bukan ditolak.
     *
     * Unique key `po_dataset_indicator_unit_period_unique` TIDAK disentuh.
     * Kolom ini bukan bagian identitas baris, jadi menambahnya tidak mengubah
     * idempotensi per (dataset, indikator, unit, periode).
     *
     * Reversible: down() menjatuhkan kedua kolom.
     */
    public function up(): void
    {
        Schema::table('performance_observations', function (Blueprint $table) {
            $table->string('sheet_period')->nullable()->after('period');
            $table->boolean('period_conflict')->default(false)->after('sheet_period');
        });
    }

    public function down(): void
    {
        Schema::table('performance_observations', function (Blueprint $table) {
            $table->dropColumn(['sheet_period', 'period_conflict']);
        });
    }
};
