<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DDL tests run only in the isolated database enforced by Tests\TestCase. */
class TechnicianDesignationMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_technicians_remain_unassigned_until_admin_creates_a_designation_and_rerun_is_safe(): void
    {
        $migration = require database_path('migrations/2026_10_31_000350_add_technician_designations_and_escalations.php');
        $migration->down();
        $technician = DB::table('users')->insertGetId(['name' => 'Existing technician', 'email' => 'legacy-tech@example.test',
            'password' => 'unused-test-value', 'role' => 'technician', 'status' => 'inactive']);
        $admin = DB::table('users')->insertGetId(['name' => 'Existing admin', 'email' => 'legacy-admin@example.test',
            'password' => 'unused-test-value', 'role' => 'admin', 'status' => 'active']);
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $technician, 'designation_id' => null, 'workforce_type' => 'employee', 'status' => 'inactive']);
        $this->assertDatabaseHas('users', ['id' => $admin, 'designation_id' => null, 'workforce_type' => null]);
        $this->assertSame(0, DB::table('technician_designations')->count());
    }
}
