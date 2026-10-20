<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionSeederGuardTest extends TestCase
{
    public function test_business_fixtures_are_guarded_before_first_demo_customer(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/database/seeders/AmcPlatformSeeder.php');
        self::assertIsString($source);

        $guard = "if (! app()->environment(['local', 'testing']) || ! env('AMC_SEED_DEMO_USERS', false)) return;";
        $guardPosition = strpos($source, $guard);
        $firstFixture = strpos($source, "Customer::updateOrCreate(['customer_code'=>'AMC-0001']");

        self::assertNotFalse($guardPosition, 'The demo-only production exclusion must exist.');
        self::assertNotFalse($firstFixture, 'Known demo fixture source changed: review seeder explicitly.');
        self::assertLessThan($firstFixture, $guardPosition, 'Guard must run before creating demo business records.');
    }
}
