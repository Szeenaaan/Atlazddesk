<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_live_health_endpoint_responds_successfully(): void
    {
        // This installation hosts a separate React frontend; Laravel's '/' route is not required.
        $this->get('/api/v2/health/live')->assertOk()->assertJsonPath('status', 'ok');
    }
}
