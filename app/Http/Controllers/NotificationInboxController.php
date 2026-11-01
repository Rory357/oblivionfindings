<?php

namespace App\Http\Controllers;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\User;
use App\Services\ControlRoom\BroadcastRecipientReadService;
use App\Services\HealthSafety\HealthSafetyInboxReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Medication\Alerts\MedicationAlertAttendance;
use App\Support\ControlRoom\BroadcastRecipientReadHttp;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

class NotificationInboxController extends Controller
{
    public function __construct(
        private readonly BoardPackAccessService $boardPackAccess,
    ) {}

    public function markRead(Request $request, string $notification)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($response = app(BroadcastRecipientReadHttp::class)->single($request, $notification)) {
            return $response;
        }

        // A staff message is never marked read by this generic action. Answer the
        // viewer's own one without a write; anything else stays a plain 404.
        if (app(BroadcastRecipientReadService::class)->isOwnBroadcastNotice($user, $notification)) {
            return back()->with('info', 'Staff messages are marked as read separately.');
        }

        $inbox = app(HealthSafetyInboxReads::class);
        // Prove the notice is visible to the viewer now, then mark only that row of their own.
        $n = $inbox->ownNotice($user, $inbox->within($user, fn (?HealthSafetyReadContext $context): string => (string) $this->boardPackAccess
            ->visibleNotificationQuery($user, healthSafetyContext: $context)
            ->where('notifications.id', $notification)
            ->firstOrFail(['notifications.id'])->id));
        $n->markAsRead();
        // A private follow-up notice: the read flag is all that changes; the follow-up stays open.
        $inbox->confirmRead($n, $user);
        // Opening a medication alert can attend it (P11 B2 chunk 3).
        $this->attend($n, $user, MedicationAlertAttendance::OPENED);

        // Keep UX snappy when called from header dropdown
        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($response = app(BroadcastRecipientReadHttp::class)->bulk($request)) {
            return $response;
        }

        // Only notices the viewer can see right now; a hidden private notice stays unread.
        $inbox = app(HealthSafetyInboxReads::class);
        $inbox->markOwnRead($user, $inbox->within($user, fn (?HealthSafetyReadContext $context): array => $this->boardPackAccess
            ->visibleNotificationQuery($user, unreadOnly: true, healthSafetyContext: $context)
            ->pluck('notifications.id')->map(fn ($id) => (string) $id)->all()));

        return back()->with('success', 'Other notifications marked as read. Staff messages have a separate read action.');
    }

    public function acknowledge(Request $request, string $notification)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($response = app(BroadcastRecipientReadHttp::class)->acknowledge($request)) {
            return $response;
        }

        // Prove the notice is visible to the viewer now, then acknowledge only that row of their own.
        $inbox = app(HealthSafetyInboxReads::class);
        $n = $inbox->ownNotice($user, $inbox->within($user, fn (?HealthSafetyReadContext $context): string => (string) $this->boardPackAccess
            ->visibleNotificationQuery($user, healthSafetyContext: $context)
            ->where('notifications.id', $notification)
            ->firstOrFail(['notifications.id'])->id));

        // Only acknowledge if this notification requests acknowledgement.
        if (! (bool) data_get($n->data, 'ack_required', false)) {
            return back()->with('info', 'No acknowledgement required for this notification.');
        }
        if (is_null($n->acknowledged_at)) {
            $n->forceFill(['acknowledged_at' => now()])->save();
        }
        $this->attend($n, $user, MedicationAlertAttendance::ACKNOWLEDGED);

        return back()->with('success', 'Notification acknowledged.');
    }

    public function reconcileBroadcastRead(Request $request, string $command_id)
    {
        abort_unless($request->user(), 403);

        return app(BroadcastRecipientReadHttp::class)->reconcile($request, $command_id);
    }

    /**
     * A medication alert's shared "attended" record (P11 B2 chunk 3). Never
     * stops the bell action itself.
     */
    private function attend(DatabaseNotification $notification, User $user, string $how): void
    {
        try {
            app(MedicationAlertAttendance::class)->fromNotification($notification, $user, $how);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
