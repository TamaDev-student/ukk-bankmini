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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('account_number', 30)->unique(); // nomor rekening
            $table->string('student_number', 30)->unique(); // NIS

            $table->string('student_name', 100);
            $table->string('class', 20);

            $table->enum('status', [
                'active',
                'blocked',
            ])->default('active');

            $table->string('security_pin', 255); // PIN
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
