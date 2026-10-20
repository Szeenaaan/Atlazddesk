<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amc_telemetry_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('sample_percent')->default(10);
            $table->unsignedSmallInteger('retention_days')->default(7);
            $table->unsignedInteger('slow_request_ms')->default(1000);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('amc_request_traces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->char('trace_id', 32)->index();
            $table->char('span_id', 16);
            $table->char('parent_span_id', 16)->nullable();
            $table->string('route', 190);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status_code');
            $table->decimal('duration_ms', 12, 2);
            $table->decimal('db_duration_ms', 12, 2);
            $table->unsignedInteger('db_query_count');
            $table->decimal('peak_memory_mb', 10, 2);
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['route', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_request_traces');
        Schema::dropIfExists('amc_telemetry_settings');
    }
};
