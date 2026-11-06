<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->string('purpose', 32)->default('password_reset')->after('user_id');
            $table->index(['user_id', 'purpose', 'consumed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'purpose', 'consumed_at', 'expires_at']);
            $table->dropColumn('purpose');
        });
    }
};
