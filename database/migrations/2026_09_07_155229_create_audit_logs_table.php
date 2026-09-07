<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log append-only: setiap perubahan data penting tercatat.
     * Sengaja TANPA updated_at dan tanpa soft delete — entri tidak boleh
     * diubah/dihapus (prinsip "terkunci = tidak ada perubahan tanpa jejak").
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32); // LOGIN/LOGOUT/CREATE/UPDATE/DELETE/IMPORT/dst.
            $table->nullableMorphs('auditable'); // auditable_type + auditable_id
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
