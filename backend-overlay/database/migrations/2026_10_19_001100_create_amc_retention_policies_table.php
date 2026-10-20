<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20)->index();
            $table->string('category', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('retention_days');
            $table->unsignedTinyInteger('run_every_days')->default(1);
            $table->unsignedSmallInteger('batch_size')->default(100);
            $table->unsignedTinyInteger('max_batches')->default(2);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_deleted_count')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_retention_policies');
    }
};
