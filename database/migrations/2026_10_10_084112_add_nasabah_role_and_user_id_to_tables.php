
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Menambahkan role nasabah pada enum MySQL.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE users
                MODIFY COLUMN role
                ENUM('administrator', 'teller', 'supervisor', 'nasabah')
                NOT NULL
            ");
        }

        // Menghubungkan rekening dengan akun login nasabah.
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Jangan menghapus dukungan role nasabah jika masih digunakan.
        if (
            DB::getDriverName() === 'mysql' &&
            DB::table('users')->where('role', 'nasabah')->exists()
        ) {
            throw new RuntimeException(
                'Rollback tidak dapat dilakukan karena masih ada user dengan role nasabah.'
            );
        }

        // Menghapus hubungan user dengan customer.
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });

        // Mengembalikan enum MySQL ke kondisi sebelumnya.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE users
                MODIFY COLUMN role
                ENUM('administrator', 'teller', 'supervisor')
                NOT NULL
            ");
        }
    }
};
