<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amc_mail_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('driver', 30); // smtp, resend, postmark
            $table->string('from_email', 255);
            $table->string('from_name', 120);
            $table->text('credentials'); // Laravel encrypted:array cast; NEVER raw JSON
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('amc_mail_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('default_provider_id')->nullable()
                ->constrained('amc_mail_providers')->nullOnDelete();
            $table->boolean('notifications_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('amc_client_mail_settings', function (Blueprint $table): void {
            $table->foreignId('customer_id')->primary()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('amc_mail_providers')->nullOnDelete();
            $table->boolean('notifications_enabled')->nullable(); // null = inherit installation default
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\DB::table('amc_mail_settings')->insert([
            'id' => 1, 'notifications_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_client_mail_settings');
        Schema::dropIfExists('amc_mail_settings');
        Schema::dropIfExists('amc_mail_providers');
    }
};
