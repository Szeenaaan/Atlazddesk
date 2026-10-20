<?php

namespace Tests\Unit;

use App\Support\ServiceResult;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;

final class ServiceResultV10Test extends TestCase
{
    public function test_success_response_uses_consistent_fields(): void
    {
        $result = ServiceResult::ok(['id' => 17], 'Created.', 201);

        $this->assertSame([
            'success' => true,
            'statuscode' => 201,
            'message' => 'Created.',
            'data' => ['id' => 17],
        ], $result->toArray());
    }

    public function test_actions_without_a_payload_do_not_emit_fake_data(): void
    {
        $result = ServiceResult::ok(message: 'Notification marked as read.');

        $this->assertArrayNotHasKey('data', $result->toArray());
        $this->assertSame(200, $result->toArray()['statuscode']);
    }

    public function test_pagination_keeps_items_at_data_and_exposes_metadata(): void
    {
        $pages = new LengthAwarePaginator([['id' => 1]], 50, 25, 1);
        $result = ServiceResult::ok($pages, 'Retrieved.');

        $this->assertSame([['id' => 1]], $result->toArray()['data']);
        $this->assertSame(50, $result->toArray()['pagination']['total']);
        $this->assertSame(2, $result->toArray()['pagination']['last_page']);
    }
}
