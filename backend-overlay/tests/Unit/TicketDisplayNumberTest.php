<?php

namespace Tests\Unit;

use App\Models\Ticket;
use Tests\TestCase;

class TicketDisplayNumberTest extends TestCase
{
    public function test_ticket_number_has_six_digit_public_format_and_can_be_searched(): void
    {
        $ticket = new Ticket;
        $ticket->id = 123;
        $ticket->created_at = '2026-10-31 12:00:00';

        $this->assertSame('#TK2026000123', $ticket->displayNumber());
        $this->assertSame('123', Ticket::searchId('#TK2026000123'));
        $this->assertSame('123', Ticket::searchId('123'));
        $this->assertNull(Ticket::searchId('#TKABC123'));
    }
}
