<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.$connection.database");
        if ($app->environment() !== 'testing' || $connection !== 'mysql' || $database !== 'amc_platform_test'
            || $app['db']->connection()->getDatabaseName() !== 'amc_platform_test') {
            throw new \RuntimeException('Tests require the isolated mysql database amc_platform_test.');
        }
        return $app;
    }
}
