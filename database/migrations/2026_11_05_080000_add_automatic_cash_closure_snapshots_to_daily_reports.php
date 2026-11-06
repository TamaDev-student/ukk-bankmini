<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->string('status', 32)->default('draft')->change();
            $table->bigInteger('opening_balance_difference')->nullable()->default(null)->change();
            $table->bigInteger('opening_difference')->nullable()->default(null)->change();
            $table->bigInteger('closing_difference')->nullable()->default(null)->change();

            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('pending_transactions')->default(0);
            $table->unsignedBigInteger('pending_total')->default(0);
            $table->unsignedInteger('failed_transactions')->default(0);
            $table->unsignedBigInteger('failed_total')->default(0);
            $table->json('transaction_snapshot')->nullable();
            $table->timestamp('auto_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropColumn([
                'total_transactions',
                'pending_transactions',
                'pending_total',
                'failed_transactions',
                'failed_total',
                'transaction_snapshot',
                'auto_closed_at',
            ]);

        });
    }
};
