<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unit organisasi sumber KPI.
     *
     * Identity source adalah judul sheet pada XLSX KPI (TJBT, MES, RST, dst).
     * Nama dipertahankan verbatim sebagai identity, jadi unique(name) cukup:
     * tidak ada kolom slug/kode karena tidak ada sumber otoritatif untuk itu.
     */
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
