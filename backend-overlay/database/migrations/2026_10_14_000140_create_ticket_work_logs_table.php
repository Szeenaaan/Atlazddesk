<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->unsignedInteger('duration_minutes');
            $table->text('description');
            $table->timestamps();
            $table->index(['ticket_id', 'start_time']);
            $table->index(['user_id', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_work_logs');
    }
};
