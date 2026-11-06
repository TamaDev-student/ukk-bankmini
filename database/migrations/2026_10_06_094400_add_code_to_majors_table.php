<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Column is nullable so existing rows (without a code) do not
     * violate a NOT NULL constraint.  The unique index still
     * guarantees that every *non-null* code is unique.
     * Application-level validation ensures new majors always
     * receive a code.
     */
    public function up(): void
    {
        Schema::table('majors', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('majors', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
