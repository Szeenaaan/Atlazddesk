<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Jobs\DispatchTicketNotice;
use App\Models\{AmcMailProvider, Asset, Customer, TechnicalMailSetting, Ticket, TicketCategory, TicketComment, TicketPriority, TicketStatus, User};
use App\Services\Mail\{ContractorMailService, TicketBusinessMail};
use App\Services\System\TechnicalSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\{DB, Http, Log, Mail};
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class BusinessTicketMailTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    public function test_missing_email_migration_returns_an_actionable_error_instead_of_a_server_error(): void
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        \Illuminate\Support\Facades\Schema::shouldReceive('hasColumn')->once()
            ->with('amc_mail_settings', 'notification_email')->andReturn(false);
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($actor)->getJson('/api/v2/admin/mail/providers')->assertStatus(503)
            ->assertJsonPath('message', 'Email settings are unavailable because the server update is incomplete. Contact your server administrator to finish the update.');
    }

    public function test_business_and_technical_test_emails_have_nonempty_html_and_plain_mime_parts(): void
    {
        $messages = [];
        $events = new \Illuminate\Events\Dispatcher();
        $events->listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) use (&$messages): void {
            $messages[] = $event->message;
        });
        $mailer = new \Illuminate\Mail\Mailer('mime-check', app('view'), new \Symfony\Component\Mailer\Transport\NullTransport(), $events);
        Mail::shouldReceive('build')->twice()->andReturn($mailer);
        $provider = $this->provider();
        app(ContractorMailService::class)->sendTest($provider, 'admin@example.test');
        TechnicalMailSetting::create(['id' => 1, 'enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test', 'credentials' => $provider->credentials]);
        $super = User::factory()->create(['role' => 'super_admin']);
        app(TechnicalSecurityService::class)->sendTestTo($super);
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertStringContainsString('test email has reached your inbox', $message->getTextBody());
            $this->assertStringContainsString('Email delivery test', $message->getHtmlBody());
            $this->assertStringContainsString('multipart/alternative', $message->toString());
            $this->assertStringNotContainsString('private-test-secret', $message->toString());
        }
        $this->assertSame('admin@example.test', $messages[0]->getTo()[0]->getAddress());
        $this->assertSame($super->email, $messages[1]->getTo()[0]->getAddress());
    }

    private function provider(string $sender = 'sender@example.test'): AmcMailProvider
    {
        return AmcMailProvider::create(['name' => 'Business SMTP', 'driver' => 'smtp', 'from_email' => $sender,
            'from_name' => 'Business support', 'is_active' => true, 'credentials' => [
                'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'smtp-account', 'password' => 'private-test-secret',
            ]]);
    }

    private function configured(): AmcMailProvider
    {
        $provider = $this->provider();
        DB::table('amc_mail_settings')->where('id', 1)->update(['default_provider_id' => $provider->id,
            'notifications_enabled' => true, 'notification_email' => 'shared@example.test']);
        return $provider;
    }

    private function captureMail(bool $fail = false): void
    {
        Http::preventStrayRequests();
        $mailer = \Mockery::mock(\Illuminate\Mail\Mailer::class);
        foreach (['raw', 'html'] as $format) {
            $mailer->shouldReceive($format)->andReturnUsing(function ($body, $callback) use ($fail, $format) {
                if ($fail) throw new \RuntimeException('private-transport-details');
                $email = new Email(); $callback(new Message($email));
                $this->messages[] = ['to' => $email->getTo()[0]->getAddress(), 'from' => $email->getFrom()[0]->getAddress(),
                    'body' => $format === 'html' ? $email->getTextBody() : $body,
                    'html' => $format === 'html' ? $body : null, 'subject' => $email->getSubject()];
            });
        }
        Mail::shouldReceive('build')->with(\Mockery::on(fn ($config) => $config['transport'] === 'amc-authenticated-smtp'
            && $config['scheme'] === 'smtp' && $config['require_tls'] === true))->andReturn($mailer);
    }

    private function ticket(): Ticket
    {
        $customer = Customer::create(['company_name' => 'Mail customer', 'customer_code' => 'MAIL-TEST', 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'customer_user', 'customer_id' => $customer->id]);
        $category = TicketCategory::create(['name' => 'Devices', 'slug' => 'devices']);
        $asset = Asset::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'name' => 'Device', 'asset_tag' => 'MAIL-ASSET']);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'medium', 'level' => 2]);
        $status = TicketStatus::create(['name' => 'New', 'slug' => 'new']);
        return Ticket::create(['ticket_number' => 'MAIL-1', 'subject' => 'Service request', 'description' => 'Details',
            'customer_id' => $customer->id, 'asset_id' => $asset->id, 'created_by' => $owner->id,
            'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id]);
    }

    public function test_business_otps_use_default_sender_and_only_each_users_registered_email(): void
    {
        $this->configured(); $this->captureMail();
        $ticket = $this->ticket();
        $legacy = $this->provider('legacy@example.test');
        DB::table('amc_client_mail_settings')->insert(['customer_id' => $ticket->customer_id, 'provider_id' => $legacy->id]);
        foreach (['admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            $user = User::factory()->create(['role' => $role, 'customer_id' => str_starts_with($role, 'customer') ? $ticket->customer_id : null]);
            app(ContractorMailService::class)->sendSecurityCode($user, '123456');
            $message = end($this->messages);
            $this->assertSame($user->email, $message['to']);
            $this->assertSame('sender@example.test', $message['from']);
            $this->assertStringContainsString('123456', $message['body']);
        }
        $this->assertNotContains('shared@example.test', array_column($this->messages, 'to'));
        $this->assertFalse((bool) DB::table('amc_security_settings')->where('key', 'mfa_email_enabled')->value('enabled'));
    }

    public function test_super_admin_uses_only_the_technical_provider(): void
    {
        $this->configured(); $this->captureMail();
        TechnicalMailSetting::updateOrCreate(['id' => 1], ['enabled' => true, 'driver' => 'smtp',
            'from_name' => 'Technical', 'from_email' => 'technical@example.test',
            'credentials' => ['host' => 'technical.example.test', 'port' => 587, 'encryption' => 'tls']]);
        $user = User::factory()->create(['role' => 'super_admin']);
        app(TechnicalSecurityService::class)->sendSecurityCode($user, '654321');
        $this->assertSame('technical@example.test', $this->messages[0]['from']);
        $this->assertSame($user->email, $this->messages[0]['to']);
    }

    public function test_ticket_events_send_once_to_shared_inbox_and_do_not_fan_out_private_mail(): void
    {
        $this->configured(); $this->captureMail();
        $ticket = $this->ticket();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        User::factory()->create(['role' => 'customer_admin', 'customer_id' => $ticket->customer_id]);
        DB::table('amc_client_mail_settings')->insert(['customer_id' => $ticket->customer_id, 'notifications_enabled' => false]);
        $comment = TicketComment::create(['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'comment' => 'Private staff content', 'is_internal' => true]);
        $events = ['created', 'assigned', 'updated', 'commented', 'internal_note', 'attachment_uploaded', 'work_logged'];
        foreach ($events as $event) {
            $job = new DispatchTicketNotice($ticket->id, $event, null, $event === 'internal_note' ? $comment->id : null, $actor->id);
            $job->handle(); $job->handle();
        }
        app(TicketBusinessMail::class)->enqueue($ticket->id, 'work_report', 'report:1', $actor->id);
        $this->assertCount(8, $this->messages);
        $this->assertSame(['shared@example.test'], array_unique(array_column($this->messages, 'to')));
        foreach ($this->messages as $message) {
            $this->assertSame('sender@example.test', $message['from']);
            $this->assertStringContainsString('MAIL-1', $message['body']);
            $this->assertStringContainsString('Mail customer', $message['body']);
            $this->assertStringContainsString($actor->name, $message['body']);
            $this->assertStringContainsString('/?ticket='.$ticket->id, $message['body']);
            $this->assertStringContainsString('Recorded at:', $message['body']);
            $this->assertStringNotContainsString('Private staff content', $message['body']);
        }
        $this->assertStringContainsString('internal note', $this->messages[4]['subject']);
        $this->assertDatabaseCount('amc_ticket_mail_deliveries', 8);
        $this->assertSame(8, DB::table('amc_ticket_mail_deliveries')->where('status', 'sent')->count());
        $this->assertDatabaseMissing('amc_user_notifications', ['event' => 'internal_note', 'user_id' => $ticket->created_by]);
        $this->getJson('/api/v2/tickets/'.$ticket->id)->assertUnauthorized();
    }

    public function test_smtp_failure_does_not_fail_a_comment_and_retries_do_not_duplicate_an_ambiguous_send(): void
    {
        $this->configured(); $this->captureMail(true);
        $ticket = $this->ticket();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $logs = []; Log::listen(function ($event) use (&$logs) { $logs[] = $event->message.json_encode($event->context); });
        $this->actingAs($actor)->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Committed reply'])->assertCreated();
        $this->assertDatabaseHas('ticket_comments', ['comment' => 'Committed reply']);
        $delivery = DB::table('amc_ticket_mail_deliveries')->first();
        $this->assertSame('uncertain', $delivery->status);
        app(TicketBusinessMail::class)->send($delivery->id);
        $this->assertSame(1, DB::table('amc_ticket_mail_deliveries')->value('attempts'));
        $this->assertStringNotContainsString('private-transport-details', implode('', $logs));
        $this->assertStringNotContainsString('Committed reply', implode('', $logs));
    }

    public function test_shared_inbox_validation_and_smtp_edit_preserve_and_redact_secrets(): void
    {
        $this->mock(\App\Services\Mail\SmtpCredentialVerifier::class)
            ->shouldReceive('verify')->twice()->andReturnNull();
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        $provider = $this->configured();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => 'TestPassword8!']);
        $this->actingAs($actor);
        $this->putJson('/api/v2/admin/mail/default', ['default_provider_id' => $provider->id, 'notifications_enabled' => true,
            'notification_email' => null, 'current_password' => 'TestPassword8!'])->assertUnprocessable();
        $this->putJson('/api/v2/admin/mail/default', ['default_provider_id' => $provider->id, 'notifications_enabled' => true,
            'notification_email' => 'sender@example.test', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->putJson('/api/v2/admin/mail/default', ['default_provider_id' => $provider->id, 'notifications_enabled' => true,
            'notification_email' => 'sender@example.test', 'current_password' => 'TestPassword8!'])->assertOk();
        $this->patchJson('/api/v2/admin/mail/providers/'.$provider->id, ['credentials' => ['host' => 'updated.example.test', 'password' => ''],
            'current_password' => 'TestPassword8!'])->assertOk()->assertDontSee('private-test-secret');
        $this->assertSame('private-test-secret', $provider->fresh()->credentials['password']);
        $this->getJson('/api/v2/admin/mail/providers')->assertOk()->assertJsonPath('data.notification_email', 'sender@example.test')
            ->assertDontSee('private-test-secret');
        foreach (['super_admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))
                ->putJson('/api/v2/admin/mail/default', [])->assertForbidden();
        }
    }

    public function test_ticket_email_waits_for_commit_and_rollback_never_sends(): void
    {
        $this->configured(); $this->captureMail();
        $ticket = $this->ticket();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($actor);
        DB::beginTransaction();
        $this->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Rolled back'])->assertCreated();
        $this->assertCount(0, $this->messages);
        DB::rollBack();
        $this->assertDatabaseMissing('ticket_comments', ['comment' => 'Rolled back']);
        $this->assertDatabaseCount('amc_ticket_mail_deliveries', 0);
        DB::beginTransaction();
        $this->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Committed'])->assertCreated();
        $this->assertCount(0, $this->messages);
        DB::commit();
        $this->assertCount(1, $this->messages);
    }

    public function test_configuration_failures_retry_but_success_and_crashed_sending_claims_are_not_replayed(): void
    {
        $this->configured(); $this->captureMail();
        $ticket = $this->ticket();
        DB::table('amc_mail_settings')->update(['notification_email' => null]);
        app(TicketBusinessMail::class)->enqueue($ticket->id, 'created', 'retry-event');
        $row = DB::table('amc_ticket_mail_deliveries')->first();
        $this->assertSame('failed', $row->status);
        $this->assertCount(0, $this->messages);
        DB::table('amc_mail_settings')->update(['notification_email' => 'shared@example.test']);
        app(TicketBusinessMail::class)->send($row->id);
        app(TicketBusinessMail::class)->send($row->id);
        $this->assertCount(1, $this->messages);
        $this->assertSame(2, DB::table('amc_ticket_mail_deliveries')->value('attempts'));
        DB::table('amc_ticket_mail_deliveries')->update(['status' => 'sending']);
        app(TicketBusinessMail::class)->send($row->id);
        $this->assertCount(1, $this->messages);
    }

    public function test_real_work_report_routes_to_shared_inbox_without_copying_private_report_content(): void
    {
        $this->configured(); $this->captureMail();
        $ticket = $this->ticket();
        $technician = User::factory()->create(['role' => 'technician', 'status' => 'active']);
        $ticket->update(['assigned_to' => $technician->id]);
        $this->actingAs($technician)->postJson('/api/v2/tickets/'.$ticket->id.'/work-reports', [
            'progress' => 'completed', 'outcome' => 'unrepairable', 'diagnosis' => 'Private diagnosis',
            'work_performed' => 'Private repair report', 'recommendation' => 'Private recommendation',
        ])->assertCreated();
        $this->assertCount(1, $this->messages);
        $this->assertSame('shared@example.test', $this->messages[0]['to']);
        $this->assertStringContainsString('Work report', $this->messages[0]['subject']);
        $this->assertStringNotContainsString('Private', $this->messages[0]['body']);
    }

    public function test_creation_and_status_update_remain_successful_when_smtp_fails(): void
    {
        $this->configured(); $this->captureMail(true);
        $ticket = $this->ticket();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $creator = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $ticket->customer_id, 'status' => 'active']);
        $this->actingAs($creator)->postJson('/api/v2/tickets', [
            'asset_id' => $ticket->asset_id, 'category_id' => $ticket->category_id,
            'priority_id' => $ticket->priority_id, 'subject' => 'Committed creation', 'description' => 'Request details',
        ])->assertCreated();
        $this->assertDatabaseHas('tickets', ['subject' => 'Committed creation', 'created_by' => $creator->id]);
        TicketStatus::create(['name' => 'Cancelled', 'slug' => 'cancelled']);
        $this->actingAs($actor)->patchJson('/api/v2/tickets/'.$ticket->id, ['status' => 'Cancelled'])->assertOk();
        $this->assertSame('cancelled', $ticket->fresh()->status->slug);
        $this->assertSame(2, DB::table('amc_ticket_mail_deliveries')->where('status', 'uncertain')->count());
    }

    public function test_ticket_html_has_an_authorized_action_plain_text_alternative_and_escaped_content(): void
    {
        $this->configured(); $this->captureMail();
        config(['amc.frontend_url' => 'https://support.example.test']);
        $ticket = $this->ticket();
        $ticket->update(['subject' => '<script>alert("unsafe")</script>']);
        (new DispatchTicketNotice($ticket->id, 'created'))->handle();
        $message = $this->messages[0];
        $this->assertStringContainsString('New ticket created', $message['html']);
        $this->assertStringContainsString('href="https://support.example.test/?ticket='.$ticket->id.'"', $message['html']);
        $this->assertStringContainsString('View ticket', $message['html']);
        $this->assertStringContainsString('Current priority', $message['html']);
        $this->assertStringContainsString('&lt;script&gt;', $message['html']);
        $this->assertStringNotContainsString('<script>', $message['html']);
        $this->assertStringContainsString('https://support.example.test/?ticket='.$ticket->id, $message['body']);
        $this->assertStringNotContainsString('<table', $message['body']);
    }

    public function test_database_mail_queue_keeps_smtp_out_of_the_ticket_request(): void
    {
        $this->configured();
        config(['queue.default' => 'sync', 'amc.ticket_mail_queue_connection' => 'database']);
        Mail::shouldReceive('build')->never(); Http::preventStrayRequests();
        $ticket = $this->ticket();
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($actor)->postJson('/api/v2/tickets/'.$ticket->id.'/comments', ['comment' => 'Queued mail'])->assertCreated();
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame(DispatchTicketNotice::class, $payload['displayName']);
        $this->assertDatabaseCount('amc_ticket_mail_deliveries', 0);
        // Simulate the notification worker; the email itself must still remain queued.
        unserialize($payload['data']['command'])->handle();
        $this->assertDatabaseHas('amc_ticket_mail_deliveries', ['ticket_id' => $ticket->id, 'status' => 'pending', 'attempts' => 0]);
        $this->assertDatabaseHas('jobs', ['queue' => 'notifications']);
        $payload = DB::table('jobs')->latest('id')->value('payload');
        $this->assertStringContainsString('SendTicketBusinessEmail', $payload);
        $this->assertStringNotContainsString('private-test-secret', $payload);
    }

    public function test_existing_api_transports_receive_html_and_plain_text(): void
    {
        $this->configured();
        Http::fake(['https://api.resend.com/*' => Http::response(['id' => 'accepted']),
            'https://api.postmarkapp.com/*' => Http::response(['MessageID' => 'accepted'])]);
        $ticket = $this->ticket();
        foreach (['resend', 'postmark'] as $driver) {
            $provider = AmcMailProvider::create(['name' => $driver, 'driver' => $driver, 'from_email' => 'sender@example.test',
                'from_name' => 'Support', 'is_active' => true, 'credentials' => ['api_key' => 'test-only-key']]);
            DB::table('amc_mail_settings')->where('id', 1)->update(['default_provider_id' => $provider->id]);
            app(TicketBusinessMail::class)->enqueue($ticket->id, 'updated', 'api:'.$driver);
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'resend.com')
            && str_contains($request['html'], 'View ticket') && str_contains($request['text'], 'MAIL-1')
            && $request['to'] === ['shared@example.test']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'postmarkapp.com')
            && str_contains($request['HtmlBody'], 'View ticket') && str_contains($request['TextBody'], 'MAIL-1')
            && $request['To'] === 'shared@example.test');
    }
}
