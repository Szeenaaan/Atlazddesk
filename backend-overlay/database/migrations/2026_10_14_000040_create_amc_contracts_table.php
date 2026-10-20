<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contract_number', 80)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('contract_type', 80)->default('Standard AMC');
            $table->decimal('contract_value', 14, 2)->nullable();
            $table->string('currency', 3)->default('AED');
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['end_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_contracts');
    }
};
