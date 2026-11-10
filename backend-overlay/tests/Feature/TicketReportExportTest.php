<?php

namespace Tests\Feature;

use App\Models\{Customer, Ticket, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class TicketReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_exports_keep_customer_scope_and_escape_content(): void
    {
        $own = Customer::create(['company_name' => 'Own client', 'customer_code' => 'EXPORT-OWN']);
        $other = Customer::create(['company_name' => 'Other client', 'customer_code' => 'EXPORT-OTHER']);
        $category = TicketCategory::create(['name' => 'Devices', 'slug' => 'export-devices']);
        $priority = TicketPriority::create(['name' => 'Medium', 'slug' => 'medium', 'level' => 2]);
        $status = TicketStatus::create(['name' => 'New', 'slug' => 'new']);
        $actor = User::factory()->create(['role' => 'customer_admin', 'customer_id' => $own->id, 'status' => 'active']);
        foreach ([[$own, '<script>private</script>'], [$other, 'Other client secret']] as [$customer, $subject]) {
            Ticket::create(['customer_id' => $customer->id, 'created_by' => $actor->id,
                'category_id' => $category->id, 'priority_id' => $priority->id, 'status_id' => $status->id,
                'ticket_number' => 'EXPORT-'. $customer->id, 'subject' => $subject, 'description' => 'Issue']);
        }
        $this->actingAs($actor);
        foreach (['xlsx' => 'xl/worksheets/sheet1.xml', 'docx' => 'word/document.xml'] as $extension => $entry) {
            $response = $this->get('/api/v2/reports/tickets.'.$extension)->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $xml = $zip->getFromName($entry);
            $zip->close();
            $this->assertStringContainsString('&lt;script&gt;private&lt;/script&gt;', $xml);
            $this->assertStringNotContainsString('Other client secret', $xml);
        }
        $pdfResponse = $this->get('/api/v2/reports/tickets.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdfPath = $pdfResponse->baseResponse->getFile()->getPathname();
        $pdf = file_get_contents($pdfPath);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString(strtoupper(bin2hex('<script>private</script>')), $pdf);
        $this->assertStringNotContainsString(strtoupper(bin2hex('Other client secret')), $pdf);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('/api/v2/reports/tickets.xlsx')->assertForbidden();
    }
}
