<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        // Each contractor has its own database, as with categories and workforce users.
        if (! Schema::hasTable('technician_designations')) {
            Schema::create('technician_designations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->unsignedInteger('level');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['is_active', 'level']);
            });
        }
        if (! Schema::hasColumn('users', 'designation_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('designation_id')->nullable()->constrained('technician_designations')->restrictOnDelete();
            });
        }
        if (! in_array('users_designation_id_foreign', array_column(Schema::getForeignKeys('users'), 'name'), true)) {
            Schema::table('users', fn (Blueprint $table) => $table->foreign('designation_id')->references('id')->on('technician_designations')->restrictOnDelete());
        }
        if (! Schema::hasColumn('users', 'workforce_type')) {
            // Existing technicians are employees, confirmed by the installation owner.
            Schema::table('users', fn (Blueprint $table) => $table->string('workforce_type', 20)->nullable());
        }
        DB::table('users')->where('role', 'technician')->whereNull('workforce_type')->update(['workforce_type' => 'employee']);
        if (! Schema::hasTable('ticket_escalations')) {
            Schema::create('ticket_escalations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
                $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('previous_designation_id')->constrained('technician_designations')->restrictOnDelete();
                $table->string('previous_designation_name', 100);
                $table->unsignedInteger('previous_level');
                $table->foreignId('assignment_id')->nullable()->constrained('ticket_assignments')->restrictOnDelete();
                $table->foreignId('selected_employee_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('selected_designation_id')->nullable()->constrained('technician_designations')->restrictOnDelete();
                $table->string('selected_designation_name', 100)->nullable();
                $table->unsignedInteger('selected_level')->nullable();
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('state', 20)->default('pending');
                $table->text('reason');
                $table->text('admin_note')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                // NULLs do not collide: retain unlimited decided requests, one pending per ticket.
                $table->unsignedBigInteger('pending_ticket_id')->nullable()
                    ->storedAs("CASE WHEN state = 'pending' THEN ticket_id ELSE NULL END");
                $table->unique('pending_ticket_id');
                $table->index(['state', 'created_at']);
                $table->index(['ticket_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_escalations');
        if (DB::getDriverName() === 'mysql' && DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'users')
            ->where('CONSTRAINT_NAME', 'users_technician_designation_required')->exists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_technician_designation_required');
        }
        if (Schema::hasColumn('users', 'designation_id')) Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('designation_id'));
        if (Schema::hasColumn('users', 'workforce_type')) Schema::table('users', fn (Blueprint $table) => $table->dropColumn('workforce_type'));
        Schema::dropIfExists('technician_designations');
    }
};
