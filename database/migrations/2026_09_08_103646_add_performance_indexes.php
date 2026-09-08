<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes untuk query paling sering dijalankan:
     * - DatasetController::index()    : WHERE division_id = ? AND status != ?
     * - PerformanceChartController    : JOIN datasets ON status = 'READY'
     *                                   WHERE division_id = ? ORDER BY period
     */
    public function up(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->index(['division_id', 'status'], 'datasets_division_status_index');
            $table->index('created_at', 'datasets_created_at_index');
        });

        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->index('dataset_id', 'pm_dataset_id_index');
            $table->index(['division_id', 'period'], 'pm_division_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->dropIndex('datasets_division_status_index');
            $table->dropIndex('datasets_created_at_index');
        });

        Schema::table('performance_metrics', function (Blueprint $table) {
            $table->dropIndex('pm_dataset_id_index');
            $table->dropIndex('pm_division_period_index');
        });
    }
};
