<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Support\AmcAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Tickets\TicketReportDocuments;

class ReportsController extends Controller
{
    private function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        abort_if(AmcAccess::isSuperAdmin($request->user()), 403);
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'status' => ['sometimes', 'string', 'max:100'],
            'priority' => ['sometimes', 'string', 'max:100'],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = \App\Support\TicketQueries::visible($request->user());
        $zone = app(\App\Services\System\InstallationTimezoneService::class)->settings()['timezone'];
        if (isset($filters['from'])) $query->where('tickets.created_at', '>=', \Carbon\CarbonImmutable::parse($filters['from'], $zone)->startOfDay()->utc());
        if (isset($filters['to'])) $query->where('tickets.created_at', '<', \Carbon\CarbonImmutable::parse($filters['to'], $zone)->startOfDay()->addDay()->utc());
        if (isset($filters['customer_id'])) $query->where('tickets.customer_id', $filters['customer_id']);
        foreach (['status', 'priority'] as $relation) {
            if (isset($filters[$relation])) $query->whereHas($relation, fn ($q) => $q->where('name', $filters[$relation]));
        }
        if (!empty($filters['search'])) {
            $term = addcslashes($filters['search'], '%_\\');
            $ticketId = \App\Models\Ticket::searchId($filters['search']);
            $query->where(fn ($q) => $q->where('tickets.subject', 'like', "%{$term}%")
                ->orWhere('tickets.ticket_number', 'like', "%{$term}%")
                ->when($ticketId, fn ($q) => $q->orWhere('tickets.id', $ticketId)));
        }
        return $query;
    }

    public function tickets(Request $request)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $query = $this->filtered($request);
        return \App\Http\Resources\AmcTicketResource::collection(
            \App\Support\GridSort::apply(\App\Support\TicketQueries::withPendingFlag($query, $request->user()), $request, ['id', 'ticket_number', 'created_at'],
                ['customer' => ['customer', 'company_name'], 'priority' => ['priority', 'level'],
                 'status' => ['status', 'name'], 'assignee' => ['assignee', 'name']], 'created_at')
                ->orderByDesc('tickets.id')->paginate(25)
        )->response()->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request)
    {
        abort_if(AmcAccess::isSuperAdmin($request->user()), 403);
        $request->validate(['timeframe' => ['sometimes', \Illuminate\Validation\Rule::in(['1h', '4h', 'day', 'week', 'month', 'year'])]]);
        $period = $request->input('timeframe');
        $end = now('UTC');
        $seconds = ['1h' => 3600, '4h' => 14400, 'day' => 86400, 'week' => 604800, 'month' => 2592000, 'year' => 31536000];
        $start = $period ? $end->copy()->subSeconds($seconds[$period]) : null;
        $base = $this->filtered($request);
        $visible = fn () => (clone $base)
            ->when($start, fn ($query) => $query->where('tickets.created_at', '>=', $start)->where('tickets.created_at', '<=', $end));
        $closed = ['resolved','closed','cancelled'];
        $total = $visible()->count();
        $open = $visible()->whereHas('status', fn ($q)=>$q->where('is_closed', false))->count();
        $resolved = $visible()->whereHas('status',fn ($q)=>$q->whereIn('slug',['resolved','closed']))->count();
        $critical = $visible()->whereHas('priority',fn ($q)=>$q->where('slug','critical'))
            ->whereHas('status',fn ($q)=>$q->whereNotIn('slug',$closed))->count();
        $unassigned = $visible()->whereNull('assigned_to')->whereHas('status',fn ($q)=>$q->whereNotIn('slug',$closed))->count();
        $technicianWork = [];
        if ($request->user()->role === 'technician') {
            $completed = ['resolved', 'customer_confirmation', 'closed'];
            $technicianWork = [
                'completed' => $visible()->whereHas('status', fn ($q) => $q->whereIn('slug', $completed))->count(),
                'unresolved' => $visible()->whereHas('status', fn ($q) => $q->whereNotIn('slug', [...$completed, 'cancelled']))->count(),
                // Only an aggregate of this technician's approved requests is exposed after reassignment.
                'escalated' => \App\Models\TicketEscalation::where('requester_id', $request->user()->id)
                    ->where('state', 'approved')
                    ->when($start, fn ($q) => $q->whereBetween('decided_at', [$start, $end]))
                    ->distinct()->count('ticket_id'),
            ];
        }
        $daily = [];
        $buckets = $period ? ['1h' => 12, '4h' => 16, 'day' => 24, 'week' => 7, 'month' => 30, 'year' => 12][$period] : 7;
        $chartStart = $start ?? $end->copy()->startOfDay()->subDays(6);
        $step = $period ? $seconds[$period] / $buckets : 86400;
        // Aggregate each timestamp once instead of issuing two queries per chart bucket.
        // Keep the existing created-at timeframe restriction on resolved counts as well.
        $chartEnd = $chartStart->copy()->addSeconds((int) ($buckets * $step));
        $counts = [];
        $inProgress = [];
        foreach (['created_at', 'resolved_at'] as $column) {
            $query = $visible()->where("tickets.$column", '>=', $chartStart)
                ->where("tickets.$column", $period ? '<=' : '<', $chartEnd)
                ->selectRaw("FLOOR(TIMESTAMPDIFF(SECOND, ?, tickets.$column) / ?) AS bucket, COUNT(*) AS total", [$chartStart, $step]);
            if ($column === 'created_at') {
                $query->join('ticket_statuses as chart_status', 'tickets.status_id', '=', 'chart_status.id')
                    ->selectRaw('SUM(CASE WHEN chart_status.slug = ? THEN 1 ELSE 0 END) AS in_progress', ['in_progress']);
            }
            $rows = $query->groupBy('bucket')->get();
            $counts[$column] = [];
            foreach ($rows as $row) {
                // An event exactly at the inclusive period end belongs to the last bucket.
                $bucket = min((int) $row->bucket, $buckets - 1);
                $counts[$column][$bucket] = ($counts[$column][$bucket] ?? 0) + (int) $row->total;
                if ($column === 'created_at') {
                    $inProgress[$bucket] = ($inProgress[$bucket] ?? 0) + (int) $row->in_progress;
                }
            }
        }
        for ($i = 0; $i < $buckets; $i++) {
            $day = $chartStart->copy()->addSeconds((int) ($i * $step));
            $daily[] = [
                'day' => $day->format('D'), 'at' => $day->toIso8601String(),
                'tickets' => $counts['created_at'][$i] ?? 0,
                'in_progress' => $inProgress[$i] ?? 0,
                'resolved' => $counts['resolved_at'][$i] ?? 0,
            ];
        }
        $priorities = $visible()->join('ticket_priorities', 'tickets.priority_id', '=', 'ticket_priorities.id')
            ->selectRaw('ticket_priorities.name, COUNT(*) AS total')->groupBy('ticket_priorities.name')->pluck('total', 'name');
        $recent = $period ? \App\Http\Resources\AmcTicketResource::collection(
            \App\Support\TicketQueries::withPendingFlag(\App\Support\TicketQueries::visible($request->user()), $request->user())
                ->where('tickets.created_at', '>=', $start)->where('tickets.created_at', '<=', $end)
                ->latest('tickets.created_at')->orderByDesc('tickets.id')->limit(5)->get()
        )->resolve($request) : [];
        return response()->json([
            'total'=>$total,'open'=>$open,'resolved'=>$resolved,'critical_open'=>$critical,'unassigned_open'=>$unassigned,
            'daily'=>$daily,'generated_at'=>$end->toIso8601String(),
            'priorities'=>$priorities, 'recent_tickets'=>$recent, 'timeframe'=>$period,
            ...$technicianWork,
            'from'=>$start?->toIso8601String(), 'to'=>$end->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function ticketCsv(Request $request): StreamedResponse
    {
        abort_if(AmcAccess::isSuperAdmin($request->user()), 403);
        $query = $this->filtered($request)->orderBy('tickets.id');
        return response()->streamDownload(function () use ($query) {
            $out=fopen('php://output','wb');
            fputcsv($out,['Ticket','Subject','Client','Priority','Status','Assignee','Created']);
            // Prevent formula execution when CSV is opened in spreadsheet software.
            $safe=fn ($value) => preg_match('/^[\s]*[=+\-@]/u',(string)$value)
                    ? "'".(string)$value : (string)$value;
            $query->chunkById(500, function ($tickets) use ($out, $safe) {
                foreach ($tickets as $ticket) {
                    fputcsv($out, array_map($safe, [
                    $ticket->displayNumber(),$ticket->subject,$ticket->customer?->company_name,
                    $ticket->priority?->name,$ticket->status?->name,$ticket->assignee?->name,
                    $ticket->created_at?->toDateTimeString(),
                ]));
                }
                fflush($out);
            });
            fclose($out);
        }, 'supportdesk-tickets-'.now()->format('Ymd').'.csv', [
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'no-store',
        ]);
    }

    public function ticketDocument(Request $request, string $format, TicketReportDocuments $documents)
    {
        abort_unless(in_array($format, ['docx', 'xlsx', 'pdf'], true), 404);
        $query = $this->filtered($request);
        abort_if((clone $query)->count() > 10000, 422, 'Narrow the report filters to 10,000 tickets or fewer.');
        $rows = $documents->rows($query);
        $path = $format === 'pdf' ? $documents->createPdf($rows) : $documents->create($format, $rows);
        $mime = match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/pdf',
        };
        return response()->download($path, 'supportdesk-tickets-'.now()->format('Ymd').'.'.$format, [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
