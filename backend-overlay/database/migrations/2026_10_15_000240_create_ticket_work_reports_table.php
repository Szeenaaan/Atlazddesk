<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_work_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->string('progress', 30);
            $table->string('outcome', 30);
            $table->text('diagnosis');
            $table->text('work_performed')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('parts_required', 500)->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
            $table->index(['technician_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_work_reports');
    }
};
