<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('critical_response_minutes')->default(15);
            $table->unsignedInteger('critical_resolution_minutes')->default(240);
            $table->unsignedInteger('high_response_minutes')->default(30);
            $table->unsignedInteger('high_resolution_minutes')->default(480);
            $table->unsignedInteger('medium_response_minutes')->default(120);
            $table->unsignedInteger('medium_resolution_minutes')->default(1440);
            $table->unsignedInteger('low_response_minutes')->default(240);
            $table->unsignedInteger('low_resolution_minutes')->default(2880);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
