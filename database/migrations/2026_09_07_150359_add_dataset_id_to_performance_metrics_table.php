<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tautkan setiap baris performance metric ke dataset sumbernya.
     * - Dataset di-archive -> baris ikut dikecualikan dari agregasi chart.
     * - Dataset dihapus permanen -> baris ikut terhapus (cascade), karena
     *   baris tanpa dataset sumber tidak bisa diaudit.
     */
    public function up(): void
    {
        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->foreignId('dataset_id')->nullable()->after('division_id')
                ->constrained('datasets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dataset_id');
        });
    }
};
