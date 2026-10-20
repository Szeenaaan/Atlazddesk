<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amc_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->index();
            $table->string('kind', 32)->index();
            $table->string('severity', 16)->default('error');
            $table->string('exception_class', 255)->nullable();
            $table->string('message', 1000);
            $table->string('request_id', 64)->nullable()->index();
            $table->string('route', 255)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('amc_incidents'); }
};
