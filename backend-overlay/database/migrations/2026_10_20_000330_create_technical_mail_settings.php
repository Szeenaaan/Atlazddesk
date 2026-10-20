<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_technical_mail_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('driver', 20)->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name', 120)->nullable();
            // Laravel encrypted cast; must never be returned in serialized responses.
            $table->longText('credentials')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_technical_mail_settings');
    }
};
