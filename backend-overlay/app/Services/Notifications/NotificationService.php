<?php

namespace App\Services\Notifications;

use App\Models\AmcUserNotification;
use App\Support\ServiceResult;
use Illuminate\Http\Request;

/** Reads and marks only notifications belonging to the authenticated user. */
final class NotificationService
{
    public function list(Request $request, bool $unreadOnly = false): ServiceResult
    {
        $this->authorize($request);
        $query = AmcUserNotification::query()->where('user_id', $request->user()->id);
        $unreadCount = (clone $query)->whereNull('read_at')->count();
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return ServiceResult::ok([
            'unread_count' => $unreadCount,
            'items' => $query->orderByDesc('id')->limit(30)
                ->get(['id', 'ticket_id', 'event', 'title', 'message', 'read_at', 'created_at']),
        ], 'Notifications retrieved.');
    }

    public function markRead(Request $request, int $notificationId): ServiceResult
    {
        $this->authorize($request);
        $notification = AmcUserNotification::query()
            ->where('user_id', $request->user()->id)->findOrFail($notificationId);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return ServiceResult::ok(message: 'Notification marked as read.');
    }

    private function authorize(Request $request): void
    {
        abort_if($request->user()?->role === 'super_admin', 403);
    }
}
