<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->bigInteger('opening_balance')->nullable()->default(null)->change();
            $table->bigInteger('system_balance')->nullable()->default(null)->change();
            $table->string('opening_balance_source', 32)->nullable();
            $table->foreignId('opening_balance_recorded_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('opening_balance_recorded_at')->nullable();
        });

        Schema::create('daily_report_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')
                ->constrained('daily_reports')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedBigInteger('previous_opening_balance')->nullable();
            $table->unsignedBigInteger('corrected_opening_balance');
            $table->text('reason');
            $table->timestamps();

            $table->index(['daily_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_corrections');

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropForeign(['opening_balance_recorded_by']);
            $table->dropColumn([
                'opening_balance_source',
                'opening_balance_recorded_by',
                'opening_balance_recorded_at',
            ]);
            $table->bigInteger('opening_balance')->default(0)->change();
            $table->bigInteger('system_balance')->default(0)->change();
        });
    }
};
