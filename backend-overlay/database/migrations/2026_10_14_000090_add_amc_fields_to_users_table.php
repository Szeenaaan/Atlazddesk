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
            $table->dropIndex(['customer_id', 'role']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['role', 'phone', 'status', 'last_login_at']);
        });
    }
};
