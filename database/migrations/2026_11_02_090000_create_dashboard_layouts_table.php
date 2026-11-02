<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel konfigurasi susunan widget dashboard per role/divisi (Fase 3.2).
     *
     * Kontrak skema (Opsi 1A & 2A):
     * - layout_key  : identitas unik deterministik (<role_slug> atau
     *                 staff_divisi:<division.slug>), menjamin uniqueness
     *                 di MySQL 8.4 maupun SQLite termasuk saat division_id NULL.
     * - role_slug   : slug role target (admin, management, editor_dashboard,
     *                 staff_divisi).
     * - division_id : opsional, hanya boleh terisi untuk staff_divisi.
     * - widgets     : JSON list<string> terurut berisi widget_key dari katalog.
     * - updated_by  : referensi user pemegang dashboard.update yang terakhir
     *                 menyimpan susunan layout.
     */
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('layout_key')->unique();
            $table->string('role_slug')->index();
            $table->foreignId('division_id')
                ->nullable()
                ->constrained('divisions')
                ->cascadeOnDelete();
            $table->json('widgets');
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
