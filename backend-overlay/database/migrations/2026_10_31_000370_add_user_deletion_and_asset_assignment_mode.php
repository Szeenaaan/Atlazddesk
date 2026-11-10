<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('assets', fn (Blueprint $table) => $table->string('assignment_mode', 20)->default('shared')->index());
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('assignment_mode'));
        Schema::table('users', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
