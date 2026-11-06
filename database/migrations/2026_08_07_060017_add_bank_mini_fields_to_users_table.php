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
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->unique()->after('name');
            $table->string('full_name', 100)->after('username');
            $table->enum('role', ['administrator', 'teller', 'supervisor', 'nasabah'])->after('full_name');
            $table->enum('status', ['active', 'blocked'])->default('active')->after('role');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('username');

            $table->dropColumn([
                'username',
                'full_name',
                'role',
                'status',
            ]);
        });
    }
};
