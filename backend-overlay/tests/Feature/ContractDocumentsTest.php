<?php

namespace Tests\Feature;

use App\Models\{AmcContract, Customer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

final class ContractDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function contract(string $number = 'DOC-001'): AmcContract
    {
        $customer = Customer::create(['company_name' => 'Document Customer', 'customer_code' => $number]);
        return AmcContract::create(['customer_id' => $customer->id, 'contract_number' => $number,
            'contract_type' => 'Annual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['amc.attachments_enabled' => true]);
    }

    public function test_admin_can_upload_list_view_and_download_a_private_pdf(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $contract = $this->contract();
        $url = "/api/v2/contracts/{$contract->id}/documents";
        $pdf = UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $id = $this->actingAs($actor)->post($url, ['file' => $pdf], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.file_name', 'agreement.pdf')->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.file_path');
        $stored = DB::table('contract_documents')->find($id);
        Storage::disk('local')->assertExists($stored->file_path);
        $view = $this->get("$url/$id")->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $view->headers->get('Content-Disposition'));
        $download = $this->get("$url/$id?download=1")->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $other = $this->contract('DOC-002');
        $this->get("/api/v2/contracts/{$other->id}/documents/$id")->assertNotFound();
    }

    public function test_size_extension_and_content_are_validated_on_the_server(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $contract = $this->contract();
        $url = "/api/v2/contracts/{$contract->id}/documents";
        $this->actingAs($actor);
        $html = UploadedFile::fake()->createWithContent('payload.html', '<html><script>alert(1)</script></html>');
        foreach ([
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
            UploadedFile::fake()->createWithContent('payload.exe', 'plain text'),
            new UploadedFile($html->getPathname(), 'payload.pdf', null, null, true),
        ] as $file) {
            $this->post($url, ['file' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('contract_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_non_admin_roles_cannot_list_upload_or_read_documents(): void
    {
        $contract = $this->contract();
        foreach (['super_admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'status' => 'active', 'customer_id' => $contract->customer_id]);
            $url = "/api/v2/contracts/{$contract->id}/documents";
            $this->actingAs($actor)->getJson($url)->assertForbidden();
            $this->postJson($url, [])->assertForbidden();
            $this->getJson("$url/1")->assertForbidden();
        }
    }

    public function test_upload_enable_setting_is_preserved_and_text_files_download(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $contract = $this->contract();
        $url = "/api/v2/contracts/{$contract->id}/documents";
        $this->actingAs($actor);
        config(['amc.attachments_enabled' => false]);
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('terms.txt', 'Contract terms')], ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertDatabaseCount('contract_documents', 0);
        config(['amc.attachments_enabled' => true]);
        $id = $this->post($url, ['file' => UploadedFile::fake()->createWithContent('terms.txt', 'Contract terms')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $response = $this->get("$url/$id")->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }
}
