<?php

namespace App\Services\Mail;

use App\Jobs\SendTicketBusinessEmail;
use App\Models\{Ticket, User};
use Illuminate\Support\Facades\{DB, Log};

/** One shared-inbox delivery per committed ticket event; no user-recipient mail fan-out. */
final class TicketBusinessMail
{
    public function enqueue(int $ticketId, string $event, string $eventKey, ?int $actorId = null, ?int $commentId = null, ?string $occurredAt = null): void
    {
        if (! in_array($event, ['created', 'assigned', 'updated', 'commented', 'internal_note', 'attachment_uploaded', 'work_logged', 'work_report', 'sla_warning', 'sla_breach'], true)) return;
        try {
            if (! DB::table('amc_mail_settings')->where('id', 1)->value('notifications_enabled')) return;
            $key = hash('sha256', $ticketId.':'.$event.':'.$eventKey);
            DB::table('amc_ticket_mail_deliveries')->insertOrIgnore([
                'event_key' => $key, 'ticket_id' => $ticketId, 'event' => $event, 'actor_id' => $actorId,
                'comment_id' => $commentId, 'status' => 'pending', 'occurred_at' => $occurredAt ?? now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $delivery = DB::table('amc_ticket_mail_deliveries')->where('event_key', $key)->first();
            if ($delivery && in_array($delivery->status, ['pending', 'failed'], true)) {
                SendTicketBusinessEmail::dispatch($delivery->id)->afterCommit();
            }
        } catch (\Throwable) {
            // A synchronous queue or unavailable queue must never turn a committed action into an HTTP error.
            Log::channel('amc_mail')->warning('ticket_mail.enqueue_failed', ['ticket_id' => $ticketId, 'event' => $event]);
        }
    }

    public function send(int $id): void
    {
        $delivery = DB::transaction(function () use ($id) {
            $row = DB::table('amc_ticket_mail_deliveries')->lockForUpdate()->find($id);
            if (! $row || ! in_array($row->status, ['pending', 'failed'], true) || $row->attempts >= 3) return null;
            DB::table('amc_ticket_mail_deliveries')->where('id', $id)->update([
                'status' => 'sending', 'attempts' => $row->attempts + 1, 'failure_category' => null, 'updated_at' => now(),
            ]);
            return $row;
        });
        if (! $delivery) return;
        $transportStarted = false;
        try {
            $ticket = Ticket::with(['customer', 'status', 'priority', 'assignee'])->find($delivery->ticket_id);
            $route = app(ContractorMailService::class)->ticketRoute();
            if (! $ticket || ! $route) {
                DB::table('amc_ticket_mail_deliveries')->where('id', $id)->update(['status' => 'skipped', 'updated_at' => now()]);
                return;
            }
            [$provider, $inbox] = $route;
            $private = in_array($delivery->event, ['internal_note', 'work_logged', 'work_report'], true);
            $event = $delivery->event === 'internal_note' ? 'Internal note' : ucfirst(str_replace('_', ' ', $delivery->event));
            $heading = match ($delivery->event) {
                'created' => 'New ticket created', 'assigned' => 'Ticket assignment updated',
                'commented' => 'New ticket comment', 'internal_note' => 'New internal note',
                'work_report' => 'Work report submitted', 'work_logged' => 'Work log added',
                'attachment_uploaded' => 'Attachment added', 'sla_warning' => 'Ticket SLA warning',
                'sla_breach' => 'Ticket SLA breached', default => 'Ticket updated',
            };
            $actor = $delivery->actor_id ? User::find($delivery->actor_id)?->name : null;
            $url = rtrim((string) (config('amc.frontend_url') ?: config('app.url')), '/').'/?ticket='.$ticket->id;
            // No comment body, work report or uploaded file is copied into an email.
            $text = "Ticket: {$ticket->ticket_number}\nCustomer: {$ticket->customer?->company_name}\n"
                ."Actor: ".($actor ?? 'System')."\nEvent: {$event}\nRecorded at: {$delivery->occurred_at} UTC\n"
                .($private ? "Internal staff update. Sign in with authorized staff access.\n" : '')
                ."View ticket: {$url}";
            $html = view('mail.ticket-update', [
                'ticket' => $ticket, 'heading' => $heading, 'event' => $event, 'actor' => $actor ?? 'System',
                'recordedAt' => \Illuminate\Support\Carbon::parse($delivery->occurred_at, 'UTC')->format('d M Y, H:i').' UTC',
                'url' => $url, 'private' => $private, 'senderName' => $provider->from_name,
            ])->render();
            $transportStarted = true;
            app(ContractorMailService::class)->sendWithProvider($provider, $inbox, "{$heading} · {$ticket->ticket_number}", $text, $html);
            DB::table('amc_ticket_mail_deliveries')->where('id', $id)->update([
                'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $error) {
            DB::table('amc_ticket_mail_deliveries')->where('id', $id)->update([
                // SMTP has no idempotency key: a lost acknowledgement may still mean accepted mail.
                // Never automatically replay an ambiguous delivery. Configuration failures can retry.
                'status' => $transportStarted ? 'uncertain' : 'failed',
                'failure_category' => $transportStarted ? 'delivery_unconfirmed' : 'configuration', 'updated_at' => now(),
            ]);
            Log::channel('amc_mail')->warning('ticket_mail.delivery_failed', [
                'delivery_id' => $id, 'ticket_id' => $delivery->ticket_id, 'event' => $delivery->event,
                'category' => $transportStarted ? 'delivery_unconfirmed' : 'configuration',
            ]);
            // Sanitized exception only; queue failure payloads must never contain transport secrets.
            throw new \RuntimeException('Ticket email delivery failed. Reference '.$id.'.');
        }
    }
}
