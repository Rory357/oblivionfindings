<?php

namespace App\Http\Controllers;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\User;
use App\Services\ControlRoom\BroadcastRecipientReadService;
use App\Services\HealthSafety\ChemicalExposureInboxReads;
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

        $inbox = app(ChemicalExposureInboxReads::class);
        $n = DatabaseNotification::query()->findOrFail($inbox->within($user, function (?HealthSafetyReadContext $context) use ($user, $notification, $inbox): string {
            $n = $this->boardPackAccess
                ->visibleNotificationQuery($user, healthSafetyContext: $context)
                ->where('notifications.id', $notification)
                ->firstOrFail();
            $n->markAsRead();
            // A private follow-up notice: the read flag is all that changes; the follow-up stays open.
            $inbox->assertRead($n, $user, $context);

            return (string) $n->id;
        }));
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
        app(ChemicalExposureInboxReads::class)->within($user, fn (?HealthSafetyReadContext $context): int => $this->boardPackAccess
            ->visibleNotificationQuery($user, unreadOnly: true, healthSafetyContext: $context)
            ->update(['read_at' => now()]));

        return back()->with('success', 'Other notifications marked as read. Staff messages have a separate read action.');
    }

    public function acknowledge(Request $request, string $notification)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($response = app(BroadcastRecipientReadHttp::class)->acknowledge($request)) {
            return $response;
        }

        $acknowledged = app(ChemicalExposureInboxReads::class)->within($user, function (?HealthSafetyReadContext $context) use ($user, $notification): ?string {
            $n = $this->boardPackAccess
                ->visibleNotificationQuery($user, healthSafetyContext: $context)
                ->where('notifications.id', $notification)
                ->firstOrFail();

            // Only acknowledge if this notification requests acknowledgement.
            if (! (bool) data_get($n->data, 'ack_required', false)) {
                return null;
            }
            if (is_null($n->acknowledged_at)) {
                $n->forceFill(['acknowledged_at' => now()])->save();
            }

            return (string) $n->id;
        });
        if ($acknowledged === null) {
            return back()->with('info', 'No acknowledgement required for this notification.');
        }
        $this->attend(DatabaseNotification::query()->findOrFail($acknowledged), $user, MedicationAlertAttendance::ACKNOWLEDGED);

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
