<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('preventive_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('frequency', 30)->default('monthly');
            $table->date('next_due_date')->index();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['assigned_to', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_maintenance');
    }
};
