<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Http\Controllers\Controller;
use App\Services\ControlRoom\BroadcastRecipientReadContext;
use App\Services\ControlRoom\BroadcastRecipientReadService;
use App\Services\HealthSafety\HealthSafetyInboxReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Support\ControlRoom\BroadcastRecipientReadHttp;
use Illuminate\Http\Request;

class PortalNotificationController extends Controller
{
    public function __construct(
        private readonly BoardPackAccessService $boardPackAccess,
        private readonly HealthSafetyInboxReads $inbox,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        return app(BroadcastRecipientReadService::class)->respond($user, $request, function (BroadcastRecipientReadContext $context) use ($request, $user) {
            $filter = $request->input('filter', 'all');
            // Private exposure follow-up notices are read inside their H&S context (#16); everyone else reads as before.
            $props = $this->inbox->within($user, function (?HealthSafetyReadContext $hs) use ($context, $filter, $user): array {
                $query = $this->boardPackAccess->visibleNotificationQuery($user, unreadOnly: $filter === 'unread',
                    healthSafetyContext: $hs, broadcastContext: $context);

                return [
                    'notifications' => $query->orderByDesc('created_at')
                        ->paginate(30)
                        ->through(fn ($notification) => $context->project($notification) ?? [
                            'id' => $notification->id,
                            'type' => class_basename($notification->type),
                            'data' => $this->inbox->data($notification, $user, $hs),
                            'read_at' => $notification->read_at?->toISOString(),
                            'created_at' => $notification->created_at?->toISOString(),
                        ])->toArray(),
                    'unreadCount' => $this->boardPackAccess->visibleNotificationQuery($user, unreadOnly: true,
                        healthSafetyContext: $hs, broadcastContext: $context)->count(),
                ];
            });

            return inertia('portal/notifications', [
                'notifications' => $props['notifications'],
                'filter' => $filter,
                'broadcast_reader' => $context->envelope(),
                'unreadCount' => $props['unreadCount'],
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

        // Prove the notice is visible to the viewer now, then mark only that row of their own.
        $notification = $this->inbox->ownNotice($user, $this->inbox->within($user, fn (?HealthSafetyReadContext $hs): string => (string) $this->boardPackAccess
            ->visibleNotificationQuery($user, healthSafetyContext: $hs)
            ->where('notifications.id', (string) $notificationId)
            ->firstOrFail(['notifications.id'])->id));
        $notification->update(['read_at' => now()]);
        $this->inbox->confirmRead($notification, $user);

        return redirect()->back();
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($response = app(BroadcastRecipientReadHttp::class)->bulk($request)) {
            return $response;
        }

        // Only notices the viewer can see right now; a hidden private notice stays unread.
        $this->inbox->markOwnRead($user, $this->inbox->within($user, fn (?HealthSafetyReadContext $hs): array => $this->boardPackAccess
            ->visibleNotificationQuery($user, unreadOnly: true, healthSafetyContext: $hs)
            ->pluck('notifications.id')->map(fn ($id) => (string) $id)->all()));

        return redirect()->back();
    }
}
