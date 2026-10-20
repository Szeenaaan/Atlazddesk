<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_type_id')->nullable()->change();
        });
    }
    public function down(): void {
        if (DB::table('assets')->whereNull('asset_type_id')->exists()) {
            throw new \RuntimeException('Cannot restore required legacy asset types while untyped assets exist. No data was changed.');
        }
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_type_id')->nullable(false)->change();
        });
    }
};
