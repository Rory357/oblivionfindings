<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Http\Controllers\Controller;
use App\Services\ControlRoom\BroadcastRecipientReadContext;
use App\Services\ControlRoom\BroadcastRecipientReadService;
use App\Support\ControlRoom\BroadcastRecipientReadHttp;
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

        return app(BroadcastRecipientReadService::class)->respond($user, $request, function (BroadcastRecipientReadContext $context) use ($request, $user) {
            $filter = $request->input('filter', 'all');
            $query = $this->boardPackAccess->visibleNotificationQuery($user, broadcastContext: $context);

            if ($filter === 'unread') {
                $query = $this->boardPackAccess->visibleNotificationQuery($user, unreadOnly: true, broadcastContext: $context);
            }

            $notifications = $query->orderByDesc('created_at')
                ->paginate(30)
                ->through(fn ($notification) => $context->project($notification) ?? [
                    'id' => $notification->id,
                    'type' => class_basename($notification->type),
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toISOString(),
                    'created_at' => $notification->created_at?->toISOString(),
                ]);

            return inertia('portal/notifications', [
                'notifications' => $notifications,
                'filter' => $filter,
                'broadcast_reader' => $context->envelope(),
                'unreadCount' => $this->boardPackAccess->visibleNotificationQuery($user, unreadOnly: true, broadcastContext: $context)->count(),
            ]);
        });
    }

    public function markRead(Request $request, $notificationId)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($response = app(BroadcastRecipientReadHttp::class)->single($request, $notificationId)) {
            return $response;
        }

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

        if ($response = app(BroadcastRecipientReadHttp::class)->bulk($request)) {
            return $response;
        }

        $this->boardPackAccess
            ->visibleNotificationQuery($user, unreadOnly: true)
            ->update(['read_at' => now()]);

        return redirect()->back();
    }
}
