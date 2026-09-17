<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\OpsNotification;
use Illuminate\Http\Request;

class OpsNotificationController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $notifications = OpsNotification::query()
            ->where('user_id', $auth->id)
            ->orderByRaw('read_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, $notification)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        $notification = OpsNotification::query()
            ->where('user_id', $auth->id)
            ->findOrFail($notification);

        $notification->update(['read_at' => now()]);

        return redirect()->back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        OpsNotification::query()
            ->where('user_id', $auth->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }
}
