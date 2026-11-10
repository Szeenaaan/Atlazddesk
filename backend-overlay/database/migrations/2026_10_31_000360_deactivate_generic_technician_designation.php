<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('technician_designations')) return;

        // Earlier releases created this generic value for existing technicians.
        // Keep assignments intact; admins can replace it with an actual designation.
        DB::table('technician_designations')
            ->where('name', 'Technician')
            ->where('level', 1)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not reactivate a designation that an administrator may have disabled.
    }
};
