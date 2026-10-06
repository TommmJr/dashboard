<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai KPI terstruktur per (dataset, indikator, unit, periode).
     *
     * Target dan realisasi disimpan bertipe: numeric, date, text, atau kosong.
     * Kolom *_value menampung angka, *_text menampung representasi teks asli,
     * *_date menampung serial tanggal Excel yang sudah dikonversi, dan
     * *_type menandai varian mana yang terisi. Representasi sumber tidak boleh
     * hilang hanya karena ada kolom numeric.
     *
     * dataset_id nullable mengikuti pola performance_metrics.dataset_id: baris
     * tanpa dataset sumber tidak bisa diaudit, tetapi menolak NULL di sini akan
     * membuat skema lebih ketat daripada lapisan legacy yang harus dijaga
     * paritasnya. Dataset dihapus permanen -> baris ikut terhapus (cascade).
     *
     * performance_metrics TIDAK disentuh oleh migration ini. Tabel legacy tetap
     * menjadi compatibility layer sampai exit criteria deprecation terpenuhi.
     */
    public function up(): void
    {
        Schema::create('performance_observations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dataset_id')->nullable()
                ->constrained('datasets')->cascadeOnDelete();

            // Indicator dan org_unit tidak boleh ikut terhapus bersama
            // observation: keduanya data master yang dirujuk lintas dataset.
            $table->foreignId('indicator_id')->constrained()->restrictOnDelete();
            $table->foreignId('org_unit_id')->constrained()->restrictOnDelete();

            // Scoping divisi: nullable, menghapus divisi tidak menghapus nilai.
            $table->foreignId('division_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->date('period');

            $table->decimal('target_value', 18, 4)->nullable();
            $table->text('target_text')->nullable();
            $table->date('target_date')->nullable();
            $table->string('target_type');

            $table->decimal('realisasi_value', 18, 4)->nullable();
            $table->text('realisasi_text')->nullable();
            $table->date('realisasi_date')->nullable();
            $table->string('realisasi_type');

            $table->decimal('pencapaian', 18, 4)->nullable();
            $table->decimal('nilai', 18, 4)->nullable();
            $table->text('ket')->nullable();

            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();

            // Idempotency backfill: satu observation per dataset+indikator+unit+periode.
            $table->unique(
                ['dataset_id', 'indicator_id', 'org_unit_id', 'period'],
                'po_dataset_indicator_unit_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_observations');
    }
};
