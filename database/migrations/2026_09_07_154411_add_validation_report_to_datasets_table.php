<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ringkasan hasil validasi impor (severity FATAL/WARNING) disimpan
     * sebagai JSON agar bisa ditampilkan ulang di halaman detail dataset:
     * {
     *   "ok": int, "warning": int, "fatal": int,
     *   "issues": [{"row": int, "severity": "FATAL|WARNING", "message": string}, ...]
     * }
     */
    public function up(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->json('validation_report')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->dropColumn('validation_report');
        });
    }
};
