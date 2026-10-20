<?php

namespace Tests\Unit;

use App\Services\Auth\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function test_standard_rfc_6238_sha1_vector_and_one_time_replay_protection(): void
    {
        $totp = new TotpService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // RFC test secret
        $this->assertSame(1, $totp->matchStep($secret, '287082', null, 59));
        $this->assertNull($totp->matchStep($secret, '287082', 1, 59));
        $this->assertNull($totp->matchStep($secret, '000000', null, 59));
    }
}
