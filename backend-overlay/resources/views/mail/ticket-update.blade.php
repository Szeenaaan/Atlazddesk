<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $heading }}</title></head>
<body style="margin:0;padding:0;background:#f3f4f8;color:#202336;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;">
<div style="display:none;max-height:0;overflow:hidden;">{{ $heading }} · {{ $ticket->ticket_number }} · {{ $ticket->customer?->company_name }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f8;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dfe2ec;border-radius:8px;">
<tr><td style="padding:24px;border-bottom:1px solid #dfe2ec;">
    <div style="font-size:20px;line-height:28px;font-weight:bold;overflow-wrap:anywhere;">{{ $senderName }}</div>
    <div style="font-size:14px;line-height:20px;color:#62697d;margin-top:4px;">SupportDesk · Ticket notification</div>
</td></tr>
<tr><td style="padding:24px;">
    <h1 style="margin:0 0 12px;font-size:24px;line-height:32px;color:#202336;">{{ $heading }}</h1>
    <p style="margin:0 0 8px;font-weight:bold;color:#5541b7;overflow-wrap:anywhere;">{{ $ticket->ticket_number }}</p>
    <h2 style="margin:0 0 24px;font-size:20px;line-height:28px;font-weight:normal;overflow-wrap:anywhere;">{{ $ticket->subject }}</h2>
    @if ($private)
    <p style="margin:0 0 24px;padding:12px;border-left:4px solid #7058d8;background:#f1edfc;font-size:14px;line-height:20px;">Internal staff update. The details are available only to authorized staff after signing in.</p>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #dfe2ec;table-layout:fixed;">
        @foreach (['Customer' => $ticket->customer?->company_name, 'Event' => $event, 'Updated by' => $actor, 'Event time' => $recordedAt,
            'Current status' => $ticket->status?->name, 'Current priority' => $ticket->priority?->name,
            'Assigned to' => $ticket->assignee?->name ?? 'Unassigned'] as $label => $value)
        <tr><td width="36%" valign="top" style="padding:12px 12px 12px 0;border-bottom:1px solid #dfe2ec;color:#62697d;font-size:14px;line-height:20px;overflow-wrap:anywhere;">{{ $label }}</td><td valign="top" style="padding:12px 0;border-bottom:1px solid #dfe2ec;font-size:16px;line-height:24px;overflow-wrap:anywhere;">{{ $value }}</td></tr>
        @endforeach
    </table>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td bgcolor="#634dcc" style="border-radius:4px;text-align:center;"><a href="{{ $url }}" style="display:inline-block;padding:12px 24px;border:1px solid #634dcc;border-radius:4px;color:#ffffff;text-decoration:none;font-size:16px;line-height:24px;font-weight:bold;">View ticket</a></td></tr></table>
    <p style="margin:0 0 8px;font-size:14px;line-height:20px;color:#62697d;">Sign in to SupportDesk to view this ticket. Your account permissions apply.</p>
    <p style="margin:0;font-size:14px;line-height:20px;overflow-wrap:anywhere;word-break:break-word;"><a href="{{ $url }}" style="color:#5541b7;text-decoration:underline;overflow-wrap:anywhere;">{{ $url }}</a></p>
</td></tr>
<tr><td style="padding:16px 24px;border-top:1px solid #dfe2ec;color:#62697d;font-size:14px;line-height:20px;">Sent automatically by SupportDesk. Manage ticket updates in the application.</td></tr>
</table>
</td></tr></table>
</body></html>
