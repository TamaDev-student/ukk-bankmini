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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 30)->unique();
            $table->string('student_name', 150);
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('grade_level', 10);
            $table->unsignedSmallInteger('cohort_year');
            $table->unsignedSmallInteger('graduation_year');
            $table->timestamps();

            $table->index('student_number');
            $table->index('grade_level');
            $table->index('cohort_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
