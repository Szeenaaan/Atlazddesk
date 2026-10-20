<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_security_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('amc_security_settings')->insert([
            ['key' => 'mfa_totp_enabled', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mfa_email_enabled', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    public function down(): void { Schema::dropIfExists('amc_security_settings'); }
};
