<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('activity_logs', function (Blueprint $table) {
        $table->id();

        // Pengguna yang melakukan aktivitas
        $table->foreignId('user_id')
            ->nullable()
            ->constrained('users')
            ->nullOnDelete();

        // Snapshot pengguna saat aktivitas dilakukan
        $table->string('username')->nullable();
        $table->string('role')->nullable();

        // Informasi aktivitas
        $table->string('action');
        $table->text('description')->nullable();

        // Data yang terkait dengan aktivitas
        $table->string('entity_type')->nullable();
        $table->unsignedBigInteger('entity_id')->nullable();

        // Informasi tambahan
        $table->string('ip_address', 45)->nullable();
        $table->timestamps();

        $table->index('action');
        $table->index('created_at');
        $table->index(['entity_type', 'entity_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
