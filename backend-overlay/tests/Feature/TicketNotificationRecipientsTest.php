<?php
namespace Tests\Feature;

use App\Jobs\DispatchTicketNotice;
use App\Models\{Asset, AmcUserNotification, Customer, Ticket, TicketCategory, TicketComment, TicketPriority, TicketStatus, User};
use App\Services\Mail\ContractorMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TicketNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipients_privacy_comment_deduplication_and_assignment_changes(): void
    {
        \Illuminate\Support\Facades\DB::table('amc_mail_settings')->update(['notifications_enabled'=>false]);
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $customer = Customer::create(['company_name'=>'Notice customer','customer_code'=>'NOTICE']);
        $admin = User::factory()->create(['role'=>'admin','status'=>'active']);
        $tech = User::factory()->create(['role'=>'technician','status'=>'active']);
        $ca = User::factory()->create(['role'=>'customer_admin','status'=>'active','customer_id'=>$customer->id]);
        $owner = User::factory()->create(['role'=>'customer_user','status'=>'active','customer_id'=>$customer->id]);
        $peer = User::factory()->create(['role'=>'customer_user','status'=>'active','customer_id'=>$customer->id]);
        User::factory()->create(['role'=>'technician','status'=>'active']);
        User::factory()->create(['role'=>'admin','status'=>'inactive']);
        User::factory()->create(['role'=>'customer_admin','status'=>'active']);
        $category = TicketCategory::create(['name'=>'Devices','slug'=>'notice-devices']);
        $asset = Asset::create(['customer_id'=>$customer->id,'category_id'=>$category->id,'asset_tag'=>'NOTICE','name'=>'Workstation']);
        $owner->assignedAssets()->attach($asset->id);
        $peer->assignedAssets()->attach($asset->id);
        $priority = TicketPriority::create(['name'=>'High','slug'=>'notice-high','level'=>3]);
        $status = TicketStatus::create(['name'=>'New','slug'=>'new','is_closed'=>false]);
        $ticket = Ticket::create(['ticket_number'=>'NOTICE-1','customer_id'=>$customer->id,'asset_id'=>$asset->id,'created_by'=>$owner->id,'assigned_to'=>$tech->id,'category_id'=>$category->id,'priority_id'=>$priority->id,'status_id'=>$status->id,'subject'=>'Issue','description'=>'Issue']);
        (new DispatchTicketNotice($ticket->id,'created'))->handle();
        $this->assertEqualsCanonicalizing([$admin->id,$tech->id,$ca->id,$owner->id], AmcUserNotification::where('event','created')->pluck('user_id')->all());
        foreach ([$tech,$admin] as $author) {
            $comment = TicketComment::create(['ticket_id'=>$ticket->id,'user_id'=>$author->id,'comment'=>'Private content','is_internal'=>true]);
            $job = new DispatchTicketNotice($ticket->id,'internal_note',null,$comment->id,$author->id);
            $before = AmcUserNotification::where('event','internal_note')->count();
            $job->handle(); $job->handle();
            $this->assertSame($before+1, AmcUserNotification::where('event','internal_note')->count());
        }
        $this->assertSame(0, AmcUserNotification::where('event','internal_note')->whereIn('user_id',[$ca->id,$owner->id,$peer->id])->count());
        $comment = TicketComment::create(['ticket_id'=>$ticket->id,'user_id'=>$tech->id,'comment'=>'Public reply','is_internal'=>false]);
        (new DispatchTicketNotice($ticket->id,'commented',null,$comment->id))->handle();
        $this->assertEqualsCanonicalizing([$admin->id,$ca->id,$owner->id], AmcUserNotification::where('event','commented')->pluck('user_id')->all());
        foreach (['updated','assigned','attachment_uploaded'] as $event) {
            $job = new DispatchTicketNotice($ticket->id, $event, $event === 'assigned' ? $tech->id : null, null, $admin->id, $event.':1');
            $job->handle(); $job->handle();
            $this->assertEqualsCanonicalizing([$admin->id,$tech->id,$ca->id,$owner->id], AmcUserNotification::where('event',$event)->pluck('user_id')->all());
        }
        (new DispatchTicketNotice($ticket->id,'updated',null,null,$admin->id,'updated:2'))->handle();
        $this->assertSame(8, AmcUserNotification::where('event','updated')->count());
        (new DispatchTicketNotice($ticket->id,'work_logged',null,null,$tech->id,'work-log:1'))->handle();
        $this->assertEquals([$admin->id], AmcUserNotification::where('event','work_logged')->pluck('user_id')->all());
        $assignedCount = AmcUserNotification::where('event','assigned')->count();
        $ticket->update(['assigned_to'=>null]);
        (new DispatchTicketNotice($ticket->id,'assigned',$tech->id))->handle();
        $this->assertSame($assignedCount, AmcUserNotification::where('event','assigned')->count());
        $this->assertFalse(AmcUserNotification::where('message','like','%Private content%')->exists());
    }
}
