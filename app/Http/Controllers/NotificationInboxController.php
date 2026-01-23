<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationInboxController extends Controller
{
    public function markRead(Request $request, string $notification)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $n = $user->notifications()->where('id', $notification)->firstOrFail();
        $n->markAsRead();

        // Keep UX snappy when called from header dropdown
        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $user->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
