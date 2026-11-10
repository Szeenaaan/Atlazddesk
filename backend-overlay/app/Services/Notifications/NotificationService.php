<?php

namespace App\Services\Notifications;

use App\Models\AmcUserNotification;
use App\Support\AmcAccess;
use App\Support\TicketNoticeText;
use App\Support\ServiceResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Reads and marks only notifications belonging to the authenticated user. */
final class NotificationService
{
    public function list(Request $request, bool $unreadOnly = false): ServiceResult
    {
        $this->authorize($request);
        $query = $this->visible($request);
        $unreadCount = (clone $query)->whereNull('read_at')->count();
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return ServiceResult::ok([
            'unread_count' => $unreadCount,
            'items' => $query->orderByDesc('id')->limit(30)
                ->get(['id', 'ticket_id', 'event', 'title', 'message', 'read_at', 'created_at'])
                ->each(function ($notice) {
                    if ($notice->event === 'status_changed'
                        && preg_match('/^(#TK\d{10,}) moved from .+ to Cancelled\.$/u', $notice->message, $match)) {
                        $notice->title = 'Ticket cancelled';
                        $notice->message = $match[1].' was cancelled.';
                    }
                    if ($notice->event === 'escalation_rejected'
                        && $notice->message === 'Your escalation request was rejected. Contact Contractor Admin for the next steps.') {
                        $notice->message = TicketNoticeText::REJECTED_ESCALATION_MESSAGE;
                    }
                }),
        ], 'Notifications retrieved.');
    }

    public function markRead(Request $request, int $notificationId): ServiceResult
    {
        $this->authorize($request);
        $notification = $this->visible($request)->findOrFail($notificationId);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return ServiceResult::ok(message: 'Notification marked as read.');
    }

    private function visible(Request $request): Builder
    {
        $actor = $request->user();
        $query = AmcUserNotification::query()->where('user_id', $actor->id);
        // Recipient membership can change after delivery: re-check current access on every read.
        $query->where(fn ($q) => $q->whereNull('ticket_id')->orWhereHas('ticket', fn ($tickets) => AmcAccess::scopeTicket($tickets, $actor)));
        if (AmcAccess::isCustomer($actor)) {
            $query->whereNotIn('event', ['internal_note', 'work_logged', 'work_report', 'escalation_pending', 'escalation_approved', 'escalation_rejected']);
        }

        return $query;
    }

    private function authorize(Request $request): void
    {
        abort_if($request->user()?->role === 'super_admin', 403);
    }
}
