<?php
namespace Tests\Feature;
use App\Models\{Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class DashboardTimeframeTest extends TestCase
{
    use RefreshDatabase;
    public function test_period_counts_charts_and_recent_tickets_share_the_same_scoped_window(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 20)->setTime(12, 0));
        $customer = Customer::create(['company_name'=>'Own', 'customer_code'=>'OWN']);
        $other = Customer::create(['company_name'=>'Other', 'customer_code'=>'OTHER']);
        $actor = User::factory()->create(['role'=>'customer_admin','customer_id'=>$customer->id,'status'=>'active']);
        $tech = User::factory()->create(['role'=>'technician','status'=>'active']);
        $category = TicketCategory::create(['name'=>'Network','slug'=>'network']);
        $priority = TicketPriority::create(['name'=>'Critical','slug'=>'critical','level'=>4]);
        $status = TicketStatus::create(['name'=>'New','slug'=>'new','is_closed'=>false]);
        foreach ([30, 120, 1500, 500000] as $index => $minutes) {
            $ticket = Ticket::create(['customer_id'=>$customer->id,'created_by'=>$actor->id,'assigned_to'=>$tech->id,
                'category_id'=>$category->id,'priority_id'=>$priority->id,'status_id'=>$status->id,
                'ticket_number'=>'PERIOD-'.$index,'subject'=>'Issue','description'=>'Issue']);
            $ticket->forceFill(['created_at'=>now()->subMinutes($minutes), 'resolved_at'=>now()])->save();
        }
        Ticket::create(['customer_id'=>$other->id,'created_by'=>$actor->id,
            'category_id'=>$category->id,'priority_id'=>$priority->id,'status_id'=>$status->id,
            'ticket_number'=>'OTHER','subject'=>'Private','description'=>'Private']);
        $this->actingAs($actor);
        foreach (['1h'=>1,'4h'=>2,'day'=>2,'week'=>3,'month'=>3,'year'=>4] as $period => $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->getJson('/api/v2/reports/summary?timeframe='.$period)->assertOk()
                ->assertJsonPath('total',$count)->assertJsonPath('open',$count)
                ->assertJsonPath('critical_open',$count)->assertJsonCount($count,'recent_tickets');
            $this->assertEquals($count, $response->json('priorities.Critical'));
            $this->assertSame($count, array_sum(array_column($response->json('daily'), 'tickets')));
            $this->assertSame($count, array_sum(array_column($response->json('daily'), 'resolved')));
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'TIMESTAMPDIFF'));
            $this->assertCount(2, $queries, 'Chart query count must be independent of bucket count.');
            DB::disableQueryLog();
        }
        $this->actingAs($tech)->getJson('/api/v2/reports/summary?timeframe=1h')->assertOk()->assertJsonPath('total',1);
        $this->actingAs(User::factory()->create(['role'=>'super_admin','status'=>'active']))->getJson('/api/v2/reports/summary?timeframe=1h')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/v2/reports/summary?timeframe=invalid')->assertUnprocessable();
        $this->getJson('/api/v2/reports/summary')->assertOk()->assertJsonPath('total',4);
    }
}
