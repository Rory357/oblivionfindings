<?php

namespace App\Http\Controllers;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertAttendance;
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

        $n = $this->boardPackAccess
            ->visibleNotificationQuery($user)
            ->where('id', $notification)
            ->firstOrFail();
        $n->markAsRead();
        // Opening a medication alert can attend it (P11 B2 chunk 3).
        $this->attend($n, $user, MedicationAlertAttendance::OPENED);

        // Keep UX snappy when called from header dropdown
        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $this->boardPackAccess
            ->visibleNotificationQuery($user, unreadOnly: true)
            ->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function acknowledge(Request $request, string $notification)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $n = $this->boardPackAccess
            ->visibleNotificationQuery($user)
            ->where('id', $notification)
            ->firstOrFail();

        // Only acknowledge if this notification requests acknowledgement.
        $ackRequired = (bool) data_get($n->data, 'ack_required', false);
        if (! $ackRequired) {
            return back()->with('info', 'No acknowledgement required for this notification.');
        }

        if (is_null($n->acknowledged_at)) {
            $n->forceFill(['acknowledged_at' => now()])->save();
        }
        $this->attend($n, $user, MedicationAlertAttendance::ACKNOWLEDGED);

        return back()->with('success', 'Notification acknowledged.');
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
