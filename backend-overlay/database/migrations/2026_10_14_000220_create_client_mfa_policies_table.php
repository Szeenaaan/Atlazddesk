<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_client_mfa_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->boolean('totp_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('require_mfa')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('amc_user_mfa_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('totp_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('require_mfa')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Preserve existing MFA availability during the v6→v7 policy split.
        // A global v6 setting that allowed customer TOTP/email must not silently stop
        // checking enrolled customer users after this migration.
        $global = DB::table('amc_security_settings')
            ->whereIn('key', ['mfa_totp_enabled', 'mfa_email_enabled'])
            ->pluck('enabled', 'key');
        $totp = (bool) ($global['mfa_totp_enabled'] ?? false);
        $email = (bool) ($global['mfa_email_enabled'] ?? false);
        DB::table('customers')->orderBy('id')->chunkById(200, function ($customers) use ($totp, $email): void {
            $now = now();
            $rows = [];
            foreach ($customers as $customer) {
                $rows[] = [
                    'customer_id'=>$customer->id,
                    'totp_enabled'=>$totp,
                    'email_enabled'=>$email,
                    'require_mfa'=>false,
                    'updated_by'=>null,
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ];
            }
            if ($rows !== []) DB::table('amc_client_mfa_policies')->insert($rows);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('amc_user_mfa_policies');
        Schema::dropIfExists('amc_client_mfa_policies');
    }
};
