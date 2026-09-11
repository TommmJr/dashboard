<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ubah datasets.user_id dari cascadeOnDelete menjadi nullable + nullOnDelete.
     * Menghapus user tidak lagi menghapus dataset miliknya; user_id di-set NULL.
     * Konsisten dengan pola division_id dan unit_id yang sudah nullOnDelete.
     */
    public function up(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse: kembalikan ke NOT NULL + cascadeOnDelete.
     * Perhatian: jika sudah ada NULL values di user_id, rollback akan gagal
     * di MySQL karena constraint NOT NULL. Hanya aman di lingkungan dev fresh.
     */
    public function down(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
