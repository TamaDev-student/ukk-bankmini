<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->bigInteger('last_approved_balance')->nullable();
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('opening_balance_difference')->default(0);
            $table->bigInteger('opening_physical_balance')->nullable();
            $table->bigInteger('opening_difference')->default(0);
            $table->bigInteger('system_balance')->default(0);
            $table->bigInteger('physical_balance')->nullable();
            $table->bigInteger('closing_difference')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropColumn([
                'last_approved_balance',
                'opening_balance',
                'opening_balance_difference',
                'opening_physical_balance',
                'opening_difference',
                'system_balance',
                'physical_balance',
                'closing_difference',
            ]);
        });
    }
};