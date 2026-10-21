<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Support\AmcAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function summary(Request $request)
    {
        abort_if(AmcAccess::isSuperAdmin($request->user()), 403);
        $request->validate(['timeframe' => ['sometimes', \Illuminate\Validation\Rule::in(['1h', '4h', 'day', 'week', 'month', 'year'])]]);
        $period = $request->input('timeframe');
        $end = now('UTC');
        $seconds = ['1h' => 3600, '4h' => 14400, 'day' => 86400, 'week' => 604800, 'month' => 2592000, 'year' => 31536000];
        $start = $period ? $end->copy()->subSeconds($seconds[$period]) : null;
        $visible = fn () => AmcAccess::scopeTicket(Ticket::query(), $request->user())
            ->when($start, fn ($query) => $query->where('tickets.created_at', '>=', $start)->where('tickets.created_at', '<=', $end));
        $closed = ['resolved','closed','cancelled'];
        $total = $visible()->count();
        $open = $visible()->whereHas('status', fn ($q)=>$q->where('is_closed', false))->count();
        $resolved = $visible()->whereHas('status',fn ($q)=>$q->whereIn('slug',['resolved','closed']))->count();
        $critical = $visible()->whereHas('priority',fn ($q)=>$q->where('slug','critical'))
            ->whereHas('status',fn ($q)=>$q->whereNotIn('slug',$closed))->count();
        $unassigned = $visible()->whereNull('assigned_to')->whereHas('status',fn ($q)=>$q->whereNotIn('slug',$closed))->count();
        $daily = [];
        $buckets = $period ? ['1h' => 12, '4h' => 16, 'day' => 24, 'week' => 7, 'month' => 30, 'year' => 12][$period] : 7;
        $chartStart = $start ?? $end->copy()->startOfDay()->subDays(6);
        $step = $period ? $seconds[$period] / $buckets : 86400;
        for ($i = 0; $i < $buckets; $i++) {
            $day = $chartStart->copy()->addSeconds((int) ($i * $step));
            $next = $chartStart->copy()->addSeconds((int) (($i + 1) * $step));
            $daily[] = [
                'day' => $day->format('D'), 'at' => $day->toIso8601String(),
                'tickets' => $visible()->where('created_at', '>=', $day)->where('created_at', $period && $i === $buckets - 1 ? '<=' : '<', $next)->count(),
                'resolved' => $visible()->where('resolved_at', '>=', $day)->where('resolved_at', $period && $i === $buckets - 1 ? '<=' : '<', $next)->count(),
            ];
        }
        $priorities = $visible()->join('ticket_priorities', 'tickets.priority_id', '=', 'ticket_priorities.id')
            ->selectRaw('ticket_priorities.name, COUNT(*) AS total')->groupBy('ticket_priorities.name')->pluck('total', 'name');
        $recent = $period ? \App\Http\Resources\AmcTicketResource::collection(
            \App\Support\TicketQueries::visible($request->user())
                ->where('tickets.created_at', '>=', $start)->where('tickets.created_at', '<=', $end)
                ->latest('tickets.created_at')->orderByDesc('tickets.id')->limit(5)->get()
        )->resolve($request) : [];
        return response()->json([
            'total'=>$total,'open'=>$open,'resolved'=>$resolved,'critical_open'=>$critical,'unassigned_open'=>$unassigned,
            'daily'=>$daily,'generated_at'=>$end->toIso8601String(),
            'priorities'=>$priorities, 'recent_tickets'=>$recent, 'timeframe'=>$period,
            'from'=>$start?->toIso8601String(), 'to'=>$end->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function ticketCsv(Request $request): StreamedResponse
    {
        abort_if(AmcAccess::isSuperAdmin($request->user()), 403);
        $request->validate(['search'=>['sometimes','string','max:100']]);
        $query = AmcAccess::scopeTicket(Ticket::query(), $request->user())
            ->with(['customer','priority','status','assignee'])
            ->orderBy('id');
        if ($request->filled('search')) {
            $term=str_replace(['%','_'],['\\%','\\_'],(string)$request->input('search'));
            $query->where(fn ($q)=>$q->where('subject','like',"%{$term}%")
                ->orWhere('ticket_number','like',"%{$term}%"));
        }
        return response()->streamDownload(function () use ($query) {
            $out=fopen('php://output','wb');
            fputcsv($out,['Ticket','Subject','Customer','Priority','Status','Assignee','Created']);
            // Prevent formula execution when CSV is opened in spreadsheet software.
            $safe=fn ($value) => preg_match('/^[\s]*[=+\-@]/u',(string)$value)
                    ? "'".(string)$value : (string)$value;
            $query->chunkById(500, function ($tickets) use ($out, $safe) {
                foreach ($tickets as $ticket) {
                    fputcsv($out, array_map($safe, [
                    $ticket->ticket_number,$ticket->subject,$ticket->customer?->company_name,
                    $ticket->priority?->name,$ticket->status?->name,$ticket->assignee?->name,
                    $ticket->created_at?->toDateTimeString(),
                ]));
                }
                fflush($out);
            });
            fclose($out);
        }, 'atlazddesk-tickets-'.now()->format('Ymd').'.csv', [
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'no-store',
        ]);
    }
}
