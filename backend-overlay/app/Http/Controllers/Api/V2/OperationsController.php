<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{Ticket, TicketWorkLog, TicketAttachment};
use App\Support\{AmcAccess, AmcAudit, TicketQueries};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};

class OperationsController extends Controller
{
    private function ticket(Request $request, int $id): Ticket
    {
        return TicketQueries::visible($request->user())->findOrFail($id);
    }

    public function workLogs(Request $request, int $id)
    {
        $this->ticket($request, $id);
        abort_if(AmcAccess::isCustomer($request->user()), 403);
        return TicketWorkLog::where('ticket_id', $id)->latest('id')->paginate(25);
    }

    public function addWorkLog(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'technician', 403);
        $ticket = $this->ticket($request, $id);
        $data = $request->validate([
            'start_time'=>['required','date'],
            'end_time'=>['required','date','after:start_time'],
            'description'=>['required','string','max:5000'],
        ]);
        $duration = (int) round((strtotime($data['end_time']) - strtotime($data['start_time'])) / 60);
        abort_if($duration > 1440, 422, 'Maximum work log duration is 24 hours.');
        $log = DB::transaction(function () use ($data, $id, $request, $ticket, $duration) {
            $log = TicketWorkLog::create([...$data,'ticket_id'=>$id,
                'user_id'=>$request->user()->id, 'duration_minutes'=>$duration]);
            AmcAudit::record($request, 'ticket.work_logged', $ticket, ['minutes'=>$duration]);
            return $log;
        });
        return response()->json(['data'=>$log],201);
    }

    public function attachments(Request $request, int $id)
    {
        $this->ticket($request,$id);
        return TicketAttachment::where('ticket_id',$id)
            ->latest('id')->get(['id','file_name','mime_type','file_size','created_at']);
    }

    public function upload(Request $request, int $id)
    {
        $ticket = $this->ticket($request,$id);
        abort_unless(config('amc.attachments_enabled',false), 503, 'Attachment upload is disabled until malware scanning is configured.');
        $request->validate(['file'=>['required','file','max:10240','mimes:pdf,png,jpg,jpeg,txt,docx,xlsx']]);
        $file = $request->file('file');
        $path = $file->store("ticket-attachments/{$ticket->id}",'local');
        try {
            $attachment = DB::transaction(function () use ($file,$path,$ticket,$request) {
                $attachment = TicketAttachment::create([
                    'ticket_id'=>$ticket->id,'uploaded_by'=>$request->user()->id,
                    'file_name'=>mb_substr(basename($file->getClientOriginalName()),0,200),
                    'file_path'=>$path,'mime_type'=>$file->getMimeType(),'file_size'=>$file->getSize(),
                ]);
                AmcAudit::record($request,'ticket.attachment_uploaded',$ticket,['attachment_id'=>$attachment->id]);
                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return response()->json(['data'=>['id'=>$attachment->id,'file_name'=>$attachment->file_name]],201);
    }

    public function download(Request $request, int $id, int $attachment)
    {
        $this->ticket($request, $id);
        $file = TicketAttachment::where('ticket_id',$id)->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($file->file_path),404);
        return Storage::disk('local')->download($file->file_path,$file->file_name);
    }
}
