<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key',64)->unique();
            $table->string('event',80)->index();
            $table->string('subject_type',40);
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();
            $table->index(['subject_type','subject_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('amc_alert_events'); }
};
