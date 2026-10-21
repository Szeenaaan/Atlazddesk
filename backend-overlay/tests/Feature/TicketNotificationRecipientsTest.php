<?php

namespace Tests\Feature;

use App\Jobs\DispatchTicketNotice;
use App\Models\AmcUserNotification;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class TicketNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipients_privacy_comment_deduplication_and_assignment_changes(): void
    {
        DB::table('amc_mail_settings')->update(['notifications_enabled' => false]);
        Mail::fake();
        Http::preventStrayRequests();
        $customer = Customer::create(['company_name' => 'Notice customer', 'customer_code' => 'NOTICE']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $tech = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $ca = User::factory()->create(['role' => 'customer_admin', 'status' => 'active', 'customer_id' => $customer->id]);
        $owner = User::factory()->create(['role' => 'customer_user', 'status' => 'active', 'customer_id' => $customer->id]);
        $peer = User::factory()->create(['role' => 'customer_user', 'status' => 'active', 'customer_id' => $customer->id]);
        User::factory()->create(['role' => 'technician', 'status' => 'active']);
        User::factory()->create(['role' => 'admin', 'status' => 'inactive']);
        User::factory()->create(['role' => 'customer_admin', 'status' => 'active']);
        $category = TicketCategory::create(['name' => 'Devices', 'slug' => 'notice-devices']);
        $asset = Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'asset_tag' => 'NOTICE', 'name' => 'Workstation']);
        $owner->assignedAssets()->attach($asset->id);
        $peer->assignedAssets()->attach($asset->id);
        $priority = TicketPriority::create(['name' => 'High', 'slug' => 'notice-high', 'level' => 3]);
        $status = TicketStatus::create(['name' => 'New', 'slug' => 'new', 'is_closed' => false]);
        $ticket = Ticket::create(['ticket_number' => 'NOTICE-1', 'customer_id' => $customer->id, 'asset_id' => $asset->id, 'created_by' => $owner->id, 'assigned_to' => $tech->id, 'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id, 'subject' => 'Issue', 'description' => 'Issue']);
        (new DispatchTicketNotice($ticket->id, 'created'))->handle();
        $this->assertEqualsCanonicalizing([$admin->id, $tech->id, $ca->id, $owner->id], AmcUserNotification::where('event', 'created')->pluck('user_id')->all());
        foreach ([$tech, $admin] as $author) {
            $comment = TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $author->id, 'comment' => 'Private content', 'is_internal' => true]);
            $job = new DispatchTicketNotice($ticket->id, 'internal_note', null, $comment->id, $author->id);
            $before = AmcUserNotification::where('event', 'internal_note')->count();
            $job->handle();
            $job->handle();
            $this->assertSame($before + 1, AmcUserNotification::where('event', 'internal_note')->count());
        }
        $this->assertSame(0, AmcUserNotification::where('event', 'internal_note')->whereIn('user_id', [$ca->id, $owner->id, $peer->id])->count());
        $comment = TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $tech->id, 'comment' => "Public reply\n".str_repeat('details ', 40), 'is_internal' => false]);
        (new DispatchTicketNotice($ticket->id, 'commented', null, $comment->id))->handle();
        $this->assertEqualsCanonicalizing([$admin->id, $ca->id, $owner->id], AmcUserNotification::where('event', 'commented')->pluck('user_id')->all());
        $preview = AmcUserNotification::where('event', 'commented')->firstOrFail()->message;
        $this->assertStringContainsString('Public reply details', $preview);
        $this->assertStringEndsWith('.....', $preview);
        $this->assertStringNotContainsString("\n", $preview);
        $this->assertLessThan(220, mb_strlen($preview));

        foreach (['updated', 'assigned', 'attachment_uploaded'] as $event) {
            $job = new DispatchTicketNotice($ticket->id, $event, $event === 'assigned' ? $tech->id : null, null, $admin->id, $event.':1');
            $job->handle();
            $job->handle();
            $this->assertEqualsCanonicalizing([$admin->id, $tech->id, $ca->id, $owner->id], AmcUserNotification::where('event', $event)->pluck('user_id')->all());
        }
        (new DispatchTicketNotice($ticket->id, 'updated', null, null, $admin->id, 'updated:2'))->handle();
        $this->assertSame(8, AmcUserNotification::where('event', 'updated')->count());
        (new DispatchTicketNotice($ticket->id, 'work_logged', null, null, $tech->id, 'work-log:1'))->handle();
        $this->assertEquals([$admin->id], AmcUserNotification::where('event', 'work_logged')->pluck('user_id')->all());
        $assignedCount = AmcUserNotification::where('event', 'assigned')->count();
        $ticket->update(['assigned_to' => null]);
        (new DispatchTicketNotice($ticket->id, 'assigned', $tech->id))->handle();
        $this->assertSame($assignedCount, AmcUserNotification::where('event', 'assigned')->count());
        $this->assertSame(2, AmcUserNotification::where('event', 'internal_note')->where('message', 'like', '%Private content%')->count());
        $this->assertFalse(AmcUserNotification::whereIn('user_id', [$ca->id, $owner->id, $peer->id])->where('message', 'like', '%Private content%')->exists());
        $service = app(NotificationService::class);
        $request = Request::create('/api/v2/notifications');
        $request->setUserResolver(fn () => $tech);
        $result = $service->list($request);
        $this->assertSame(0, $result->data['unread_count']);
        $this->assertCount(0, $result->data['items']);
        $oldNotice = AmcUserNotification::where('user_id', $tech->id)->firstOrFail();
        try {
            $service->markRead($request, $oldNotice->id);
            $this->fail('Revoked ticket access must not allow marking its notification.');
        } catch (ModelNotFoundException) {
            $this->assertNull($oldNotice->fresh()->read_at);
        }
        AmcUserNotification::create(['user_id' => $ca->id, 'ticket_id' => $ticket->id, 'event' => 'internal_note', 'title' => 'Private', 'message' => 'Private', 'deduplication_key' => hash('sha256', 'legacy-private')]);
        $request->setUserResolver(fn () => $ca);
        DB::enableQueryLog();
        DB::flushQueryLog();
        // Baseline count/list already takes two queries; authorization must not add per-item reads.
        $baseline = AmcUserNotification::where('user_id', $ca->id);
        (clone $baseline)->whereNull('read_at')->count();
        $baseline->orderByDesc('id')->limit(30)->get();
        $this->assertSame(2, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $result = $service->list($request);
        $this->assertSame(2, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertFalse($result->data['items']->contains('event', 'internal_note'));

    }
}
