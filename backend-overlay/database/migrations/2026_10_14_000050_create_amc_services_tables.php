<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('amc_contract_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amc_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amc_service_id')->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['amc_contract_id', 'amc_service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_contract_service');
        Schema::dropIfExists('amc_services');
    }
};
