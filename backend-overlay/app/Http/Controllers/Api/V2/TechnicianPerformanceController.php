<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\TechnicianPerformance;
use App\Services\Tickets\TicketReportDocuments;
use Illuminate\Http\Request;

final class TechnicianPerformanceController extends Controller
{
    public function index(Request $request, TechnicianPerformance $performance)
    {
        return response()->json($performance->query($request)->paginate($request->integer('per_page', 25))
            ->through(fn ($user) => $performance->row($user)))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, string $format, TechnicianPerformance $performance, TicketReportDocuments $documents)
    {
        $query = $performance->query($request);
        abort_unless(in_array($format, ['csv', 'xlsx', 'docx', 'pdf'], true), 404);
        abort_if((clone $query)->count() > 10000, 422, 'Filter the technician report to 10,000 technicians or fewer.');
        $rows = $query->get()->map(fn ($user) => $performance->exportRow($user))->all();
        $filename = 'supportdesk-technicians-'.now()->format('Ymd').'.'.$format;
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'wb');
                fputcsv($out, TechnicianPerformance::HEADERS);
                foreach ($rows as $row) fputcsv($out, array_map(fn ($value) => preg_match('/^[\s]*[=+\-@]/u', (string) $value)
                    ? "'".$value : $value, $row));
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $path = $format === 'pdf'
            ? $documents->createPdf($rows, TechnicianPerformance::HEADERS, 'Technician performance')
            : $documents->create($format, $rows, TechnicianPerformance::HEADERS, 'Technician performance');
        return response()->download($path, $filename, [
            'Content-Type' => match ($format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'application/pdf',
            },
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
