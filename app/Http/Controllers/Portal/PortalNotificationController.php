<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PortalNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $filter = $request->input('filter', 'all');

        $query = $user->notifications();

        if ($filter === 'unread') {
            $query = $user->unreadNotifications();
        }

        $notifications = $query->orderByDesc('created_at')
            ->paginate(30)
            ->through(fn ($notification) => [
                'id' => $notification->id,
                'type' => class_basename($notification->type),
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
            ]);

        return inertia('portal/notifications', [
            'notifications' => $notifications,
            'filter' => $filter,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, $notificationId)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $notification = $user->notifications()->where('id', $notificationId)->firstOrFail();
        $notification->update(['read_at' => now()]);

        return redirect()->back();
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $user->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->back();
    }
}
