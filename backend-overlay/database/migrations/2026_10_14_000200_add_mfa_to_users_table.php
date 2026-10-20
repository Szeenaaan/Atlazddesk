<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mfa_totp_secret')->nullable();
            $table->text('mfa_totp_pending_secret')->nullable();
            $table->timestamp('mfa_totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('mfa_last_totp_step')->nullable();
            $table->boolean('mfa_email_enabled')->default(false);
            $table->json('mfa_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'mfa_totp_secret', 'mfa_totp_pending_secret', 'mfa_totp_confirmed_at',
            'mfa_last_totp_step', 'mfa_email_enabled', 'mfa_recovery_codes',
        ]));
    }
};
