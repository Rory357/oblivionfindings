<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * #16: how the notification surfaces (notifications page, header bell, portal, My Day, read and acknowledge) reach
 * private exposure follow-up notices.
 *
 * A viewer with no such notice reads exactly as before, with no H&S context; the shared notice query still hides every
 * private row. A viewer with one reads inside an H&S read context, so each private notice shows only while its exposure,
 * its follow-up and the viewer's own current access (hazards and wellbeing at the exposure's Site) prove it.
 */
final class ChemicalExposureInboxReads
{
    public function __construct(private readonly ChemicalExposureNotificationAccessService $access) {}

    /**
     * @template T
     *
     * @param  callable(?HealthSafetyReadContext): T  $read
     * @return T
     */
    public function within(User $viewer, callable $read): mixed
    {
        if (! $this->hasPrivateNotices($viewer)) {
            return $read(null);
        }

        return HealthSafetyReadContext::within($viewer, ChemicalExposureNotificationAccessService::PERMISSIONS,
            fn (HealthSafetyReadContext $context) => $read($context));
    }

    /** Whether any of the viewer's own notices is (or claims to be) a private follow-up notice. Reveals nothing itself. */
    public function hasPrivateNotices(User $viewer): bool
    {
        return DB::table('notifications')
            ->where('notifiable_type', $viewer->getMorphClass())
            ->where('notifiable_id', $viewer->id)
            ->where(fn ($private) => $private->where('type', ChemicalExposureFollowupAssignedNotification::class)
                ->orWhere('data->kind', 'private_followup')
                ->orWhere('data->event_key', ChemicalExposureFollowupDestinationService::EVENT_KEY))
            ->exists();
    }

    /**
     * The data a surface shows for a row it read through the shared notice query. A private follow-up notice stores no
     * words or link; they are read again from the current exposure source here.
     *
     * @return array<string, mixed>
     */
    public function data(DatabaseNotification $row, User $viewer, ?HealthSafetyReadContext $context): array
    {
        if ($row->type !== ChemicalExposureFollowupAssignedNotification::class) {
            return is_array($row->data) ? $row->data : [];
        }
        if ($context === null) {
            // The shared query never returns a private notice without the context.
            throw new LogicException('A private follow-up notice needs its H&S read context.');
        }

        return $this->access->dataForPresentation($row, $viewer, $context);
    }

    /** After a read on a private notice: confirm the read flag was kept (reading never completes the follow-up). */
    public function assertRead(DatabaseNotification $row, User $viewer, ?HealthSafetyReadContext $context): void
    {
        if ($context !== null) {
            $this->access->assertReadPersisted($row->fresh() ?? $row, $viewer, $context);
        }
    }
}
