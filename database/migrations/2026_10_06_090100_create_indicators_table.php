<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indikator KPI per unit organisasi.
     *
     * Identitas indikator bersifat PER-UNIT, bukan global: kode/nama yang sama
     * dapat muncul di sheet berbeda dengan arti berbeda. Karena itu constraint
     * adalah unique(org_unit_id, name), bukan unique(name).
     *
     * parent_id adalah self-reference yang load-bearing: bobot hanya berada di
     * baris induk, anak kosong. Nullable karena baris root tidak punya induk.
     *
     * Kolom metadata (formula, satuan, bobot, polarity) semuanya nullable:
     * tidak semua indikator memilikinya, dan nilainya seed dari XLSX, bukan
     * dikarang dari DB lama.
     */
    public function up(): void
    {
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained()->restrictOnDelete();
            $table->string('name');

            // Self-reference: hairannya nullOnDelete agar menghapus satu baris
            // tidak memusnahkan subtree-nya.
            $table->foreignId('parent_id')->nullable()
                ->constrained('indicators')->nullOnDelete();

            $table->string('aggregation_code')->nullable();
            $table->string('polarity')->nullable();
            $table->text('formula')->nullable();
            $table->string('satuan')->nullable();
            $table->string('satuan_normalized')->nullable();

            // Bobot disimpan mentah sebagai string. Sumber dapat berupa angka,
            // formula, atau band teks seperti "Max -8 Max +1,5"; memaksanya
            // masuk numeric akan menghilangkan representasi aslinya.
            $table->string('bobot')->nullable();
            $table->decimal('bobot_value', 12, 4)->nullable();
            $table->decimal('bobot_min', 12, 4)->nullable();
            $table->decimal('bobot_max', 12, 4)->nullable();

            $table->string('category')->nullable();
            $table->boolean('is_group')->default(false);
            $table->string('pencapaian_formula_kind')->nullable();
            $table->timestamps();

            $table->unique(['org_unit_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicators');
    }
};
