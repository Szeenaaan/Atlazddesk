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
        $visible = fn () => AmcAccess::scopeTicket(Ticket::query(), $request->user());
        $closed = ['resolved','closed','cancelled'];
        $total = $visible()->count();
        $open = $visible()->whereHas('status', fn ($q)=>$q->where('is_closed', false))->count();
        $resolved = $visible()->whereHas('status',fn ($q)=>$q->whereIn('slug',['resolved','closed']))->count();
        $critical = $visible()->whereHas('priority',fn ($q)=>$q->where('slug','critical'))
            ->whereHas('status',fn ($q)=>$q->whereNotIn('slug',$closed))->count();
        $unassigned = $visible()->whereNull('assigned_to')->whereHas('status',fn ($q)=>$q->whereNotIn('slug',$closed))->count();
        $daily = [];
        for ($i=6;$i>=0;$i--) {
            $day=now()->startOfDay()->subDays($i);
            $next=$day->copy()->addDay();
            $daily[]=[
                'day'=>$day->format('D'),
                'tickets'=>$visible()->where('created_at','>=',$day)->where('created_at','<',$next)->count(),
                'resolved'=>$visible()->where('resolved_at','>=',$day)->where('resolved_at','<',$next)->count(),
            ];
        }
        return response()->json([
            'total'=>$total,'open'=>$open,'resolved'=>$resolved,'critical_open'=>$critical,'unassigned_open'=>$unassigned,
            'daily'=>$daily,'generated_at'=>now()->toIso8601String(),
        ]);
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
        }, 'atlasdesk-tickets-'.now()->format('Ymd').'.csv', [
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'no-store',
        ]);
    }
}
