<?php

namespace Tests\Feature;

use App\Models\{AmcContract, Asset, Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use Tests\TestCase;

final class InputValidationTest extends TestCase
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

    public function test_partial_updates_cannot_clear_required_fields_and_omit_them_safely(): void
    {
        $contract = $this->contract();
        $category = TicketCategory::create(['name' => 'Asset category', 'slug' => 'asset-category']);
        $asset = Asset::create(['name' => 'Laptop', 'asset_tag' => 'VALIDATION-ASSET', 'customer_id' => $this->customer->id, 'category_id' => $category->id]);
        foreach ([
            ['contracts', $contract->id, ['customer_id', 'contract_number', 'contract_type', 'start_date', 'end_date', 'currency']],
            ['assets', $asset->id, ['customer_id', 'name', 'asset_tag', 'category_id']],
            ['customers', $this->customer->id, ['company_name', 'customer_code']],
        ] as [$resource, $id, $fields]) {
            foreach ($fields as $field) {
                $this->patchJson("/api/v2/directory/$resource/$id", [$field => null])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->patchJson("/api/v2/directory/contracts/{$contract->id}", ['contract_type' => 'Revised'])->assertOk();
        $this->assertSame('2026-01-01', $contract->fresh()->start_date->format('Y-m-d'));
        $this->assertSame('Laptop', $asset->fresh()->name);
    }

    public function test_contract_dates_are_compared_with_existing_dates_on_partial_updates(): void
    {
        $contract = $this->contract();
        $url = "/api/v2/directory/contracts/{$contract->id}";
        foreach ([['start_date' => '2027-01-01'], ['end_date' => '2025-12-31']] as $payload) {
            $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('end_date');
        }
        $this->patchJson($url, ['end_date' => '2026-06-30'])->assertOk();
        $this->patchJson($url, ['start_date' => '2026-06-30'])->assertOk();
        $this->assertSame('2026-06-30', $contract->fresh()->start_date->format('Y-m-d'));
        foreach (['tomorrow', '2026-02-30', '10000-01-01', '2026-01-01T12:00:00Z'] as $date) {
            $this->patchJson($url, ['start_date' => $date])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        }
    }

    public function test_contract_currency_money_and_relation_inputs_are_bounded(): void
    {
        $contract = $this->contract();
        foreach ([
            ['currency' => '123'], ['currency' => 'aed'], ['currency' => ['USD']],
            ['contract_value' => -1], ['contract_value' => '1000000000000'], ['contract_value' => '12.345'],
            ['customer_id' => 999999], ['sla_policy_id' => 999999],
        ] as $payload) {
            $this->patchJson("/api/v2/directory/contracts/{$contract->id}", $payload)
                ->assertUnprocessable()->assertJsonValidationErrors(array_key_first($payload));
        }
        $this->patchJson("/api/v2/directory/contracts/{$contract->id}", ['currency' => 'USD', 'contract_value' => '12.50'])->assertOk();
    }

    public function test_pagination_rejects_arrays_negative_and_excessive_numbers(): void
    {
        foreach (['/api/v2/directory/contracts', '/api/v2/users', '/api/v2/tickets'] as $url) {
            foreach (['page[]=1', 'page=0', 'page=-1', 'page=abc', 'page=999999999999999999999999'] as $query) {
                $this->getJson("$url?$query")->assertUnprocessable()->assertJsonValidationErrors('page');
            }
            foreach (['per_page[]=1', 'per_page=101', 'per_page=0'] as $query) {
                $this->getJson("$url?$query")->assertUnprocessable()->assertJsonValidationErrors('per_page');
            }
        }
        $this->getJson('/api/v2/directory/contracts?page=1&per_page=25')->assertOk();
    }

    public function test_passwords_names_and_nested_types_are_validated_before_persistence(): void
    {
        $payload = ['name' => 'Technician', 'email' => 'validation@example.test', 'role' => 'technician',
            'password' => 'Strong!Password123', 'password_confirmation' => 'Strong!Password123'];
        foreach ([
            ['name' => ['array']], ['name' => str_repeat('a', 256)], ['name' => '   '],
            ['email' => 'invalid'], ['password' => ['array']],
            ['password' => str_repeat('a', 1025), 'password_confirmation' => str_repeat('a', 1025)],
            ['password_confirmation' => 'Different!Password123'], ['asset_ids' => 'invalid'],
        ] as $changes) {
            $this->postJson('/api/v2/users', array_merge($payload, $changes))->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->patchJson('/api/v2/auth/profile', ['name' => 'Valid name', 'current_password' => str_repeat('x', 1025)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v2/admin/mail/default', ['notifications_enabled' => true, 'current_password' => str_repeat('x', 1025)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_inactive_ticket_lookups_and_invalid_ticket_fields_are_rejected(): void
    {
        $ticket = $this->ticket();
        $ticket->category->update(['is_active' => false]);
        $ticket->priority->update(['is_active' => false]);
        $this->postJson('/api/v2/tickets', ['subject' => 'New request', 'description' => 'Details',
            'customer_id' => $this->customer->id, 'category_id' => $ticket->category_id, 'priority_id' => $ticket->priority_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'priority_id']);
        $this->patchJson("/api/v2/tickets/{$ticket->id}", ['status' => null])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v2/tickets/{$ticket->id}/comments", ['comment' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->assertSame('Original subject', $ticket->fresh()->subject);
    }

    public function test_ticket_uploads_reject_disallowed_filename_extensions(): void
    {
        $ticket = $this->ticket();
        Storage::fake('local');
        config(['amc.attachments_enabled' => true]);
        $this->post("/api/v2/tickets/{$ticket->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('script.exe', 'plain text')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
    public function test_user_password_minimum_is_eight_characters(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role'=>'admin','status'=>'active']));
        $payload = ['name'=>'Password boundary','email'=>'boundary@example.test','role'=>'technician',
            'password'=>'Abcd12!x','password_confirmation'=>'Abcd12!x'];
        $this->postJson('/api/v2/users', array_replace($payload, ['password'=>'Abcd12!','password_confirmation'=>'Abcd12!']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v2/users', $payload)->assertCreated();
    }

}
