<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PortalNotificationController extends Controller
{
    public function __construct(
        private readonly BoardPackAccessService $boardPackAccess,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $filter = $request->input('filter', 'all');

        $query = $this->boardPackAccess->visibleNotificationQuery($user);

        if ($filter === 'unread') {
            $query = $this->boardPackAccess->visibleNotificationQuery($user, unreadOnly: true);
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
            'unreadCount' => $this->boardPackAccess->visibleNotificationQuery($user, unreadOnly: true)->count(),
        ]);
    }

    public function markRead(Request $request, $notificationId)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $notification = $this->boardPackAccess
            ->visibleNotificationQuery($user)
            ->where('id', $notificationId)
            ->firstOrFail();
        $notification->update(['read_at' => now()]);

        return redirect()->back();
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $this->boardPackAccess
            ->visibleNotificationQuery($user, unreadOnly: true)
            ->update(['read_at' => now()]);

        return redirect()->back();
    }
}
