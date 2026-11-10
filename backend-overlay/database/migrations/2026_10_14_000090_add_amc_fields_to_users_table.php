<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 40)->default('customer_user')->after('password')->index();
            $table->string('phone', 50)->nullable()->after('role');
            $table->string('status', 30)->default('active')->after('phone')->index();
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->index(['customer_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // MariaDB may use the composite index to enforce this foreign key.
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'role']);
            $table->dropColumn(['customer_id', 'role', 'phone', 'status', 'last_login_at']);
        });
    }
};
