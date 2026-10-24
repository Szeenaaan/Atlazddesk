<?php

namespace Tests\Feature;

use App\Models\{AmcContract, Asset, Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use Tests\TestCase;

final class TicketAttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->customer = Customer::create(['company_name' => 'Validation Client', 'customer_code' => 'VALIDATION']);
        $this->actingAs($this->admin);
    }

    private function contract(): AmcContract
    {
        return AmcContract::create(['customer_id' => $this->customer->id, 'contract_number' => 'VALIDATION-001',
            'contract_type' => 'Annual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    private function ticket(): Ticket
    {
        $category = TicketCategory::create(['name' => 'Hardware', 'slug' => 'hardware']);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'medium', 'level' => 2]);
        $status = TicketStatus::create(['name' => 'New', 'slug' => 'new']);
        return Ticket::create(['customer_id' => $this->customer->id, 'created_by' => $this->admin->id,
            'ticket_number' => 'VALIDATION-TICKET', 'subject' => 'Original subject', 'description' => 'Original description',
            'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id]);
    }

    public function test_clean_upload_is_scanned_before_storage_and_download_is_private(): void
    {
        config(['amc.attachments_enabled' => true]);
        Storage::fake('local');
        $ticket = $this->ticket();
        $this->mock(\App\Services\AttachmentScanner::class)->shouldReceive('scan')->once();
        $response = $this->post("/api/v2/tickets/{$ticket->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('report.txt', 'Service report')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get("/api/v2/tickets/{$ticket->id}/attachments/".$response->json('data.id').'/download')
            ->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_infected_and_unavailable_scans_do_not_save_files_or_records(): void
    {
        config(['amc.attachments_enabled' => true]);
        Storage::fake('local');
        $ticket = $this->ticket();
        foreach ([422, 503] as $status) {
            $this->mock(\App\Services\AttachmentScanner::class)->shouldReceive('scan')->once()
                ->andThrow(new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'File security check failed.'));
            $this->post("/api/v2/tickets/{$ticket->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('report.txt', 'Test')], ['Accept' => 'application/json'])->assertStatus($status);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('ticket_attachments', 0);
    }

    public function test_unauthenticated_and_unrelated_customers_cannot_upload_or_download(): void
    {
        $ticket = $this->ticket();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v2/tickets/{$ticket->id}/attachments")->assertUnauthorized();
        $other = Customer::create(['company_name' => 'Other', 'customer_code' => 'OTHER']);
        $this->actingAs(User::factory()->create(['role' => 'customer_admin', 'customer_id' => $other->id, 'status' => 'active']));
        $this->mock(\App\Services\AttachmentScanner::class)->shouldNotReceive('scan');
        $this->postJson("/api/v2/tickets/{$ticket->id}/attachments")->assertNotFound();
        $this->getJson("/api/v2/tickets/{$ticket->id}/attachments/1/download")->assertNotFound();
    }

    public function test_upload_attempts_are_rate_limited_before_scanning(): void
    {
        config(['amc.attachments_enabled' => true]);
        $ticket = $this->ticket();
        $this->mock(\App\Services\AttachmentScanner::class)->shouldNotReceive('scan');
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson("/api/v2/tickets/{$ticket->id}/attachments")->assertUnprocessable();
        }
        $this->postJson("/api/v2/tickets/{$ticket->id}/attachments")->assertStatus(429);
        $this->assertDatabaseCount('ticket_attachments', 0);
    }
}
