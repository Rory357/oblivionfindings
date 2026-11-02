<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use App\Support\SchemaCache;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * How the notification surfaces (notifications page, header bell, portal, My Day, read and acknowledge) reach the two
 * private H&S notice families: exposure follow-up notices (#16) and worker participation meeting notices (#14).
 *
 * A viewer with no such notice reads exactly as before, with no H&S context; the shared notice query still hides every
 * private row. A viewer with one reads inside an H&S read context (with its materialisation proof), so each private
 * notice shows only while its source and the viewer's own current access prove it.
 */
final class HealthSafetyInboxReads
{
    public function __construct(private readonly ChemicalExposureNotificationAccessService $exposures) {}

    /** The capabilities both private families' readers consume, prepared together. */
    public static function permissions(): array
    {
        return array_values(array_unique([...ChemicalExposureNotificationAccessService::PERMISSIONS,
            ...WorkerParticipationRecipientSourceService::PERMISSIONS]));
    }

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

        return HealthSafetyReadContext::within($viewer, self::permissions(),
            fn (HealthSafetyReadContext $context) => HealthSafetyReadMaterialisationProof::within($context, fn () => $read($context)));
    }

    /** Per request: viewers the doorway found without any private notice (so the shared query need not ask again). */
    private static ?\WeakMap $withoutPrivate = null;

    /** Whether this request's doorway already found the viewer has no private notice at all. */
    public static function knownWithoutPrivate(User $viewer): bool
    {
        return isset(self::$withoutPrivate[request()][(int) $viewer->id]);
    }

    /** Both private families in one id-only read: an exposure follow-up notice, or a meeting notice with its delivery row. */
    public function hasPrivateNotices(User $viewer): bool
    {
        $found = $this->privateNoticeExists($viewer);
        if (! $found) {
            self::$withoutPrivate ??= new \WeakMap;
            $known = self::$withoutPrivate[request()] ?? [];
            $known[(int) $viewer->id] = true;
            self::$withoutPrivate[request()] = $known;
        }

        return $found;
    }

    private function privateNoticeExists(User $viewer): bool
    {
        $deliveries = SchemaCache::hasTable('worker_participation_meeting_deliveries');

        return $this->own($viewer)
            ->where(function ($private) use ($deliveries): void {
                $private->where('type', ChemicalExposureFollowupAssignedNotification::class)
                    ->orWhere('data->kind', 'private_followup')
                    ->orWhere('data->event_key', ChemicalExposureFollowupDestinationService::EVENT_KEY);
                if ($deliveries) {
                    $private->orWhereExists(fn (QueryBuilder $delivery) => $delivery->selectRaw('1')
                        ->from('worker_participation_meeting_deliveries as private_delivery')
                        ->whereColumn('private_delivery.id', 'notifications.id'));
                }
            })
            ->limit(1)->value('notifications.id') !== null;
    }

    /** Whether any of the viewer's own notices is (or claims to be) a private exposure follow-up notice. */
    public function hasExposureNotices(User $viewer): bool
    {
        return $this->own($viewer)
            ->where(fn ($private) => $private->where('type', ChemicalExposureFollowupAssignedNotification::class)
                ->orWhere('data->kind', 'private_followup')
                ->orWhere('data->event_key', ChemicalExposureFollowupDestinationService::EVENT_KEY))
            // Its id only (no select *: nothing of the notice itself is read here).
            ->limit(1)->value('notifications.id') !== null;
    }

    /**
     * Whether the viewer has a participation meeting notice the reader could ever admit: one with its own delivery row.
     * Legacy meeting notices (CommitteeMeetingScheduled, or a copied marker) have none, so the reader never shows them and
     * the shared query only hides their marker; preparing an H&S read for them would show nothing more.
     */
    public static function hasParticipationNotices(User $viewer): bool
    {
        if (! SchemaCache::hasTable('worker_participation_meeting_deliveries')) {
            return false;
        }

        return DB::table('notifications')
            ->where('notifiable_type', $viewer->getMorphClass())
            ->where('notifiable_id', $viewer->id)
            ->whereExists(fn (QueryBuilder $delivery) => $delivery->selectRaw('1')
                ->from('worker_participation_meeting_deliveries as private_delivery')
                ->whereColumn('private_delivery.id', 'notifications.id'))
            ->limit(1)->value('notifications.id') !== null;
    }

    /**
     * The data a surface shows for a row it read through the shared notice query. A private exposure notice stores no
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
            // The shared query never returns a private exposure notice without the context.
            throw new LogicException('A private follow-up notice needs its H&S read context.');
        }

        return $this->exposures->dataForPresentation($row, $viewer, $context);
    }

    /**
     * Read and acknowledge writes. The H&S read proof re-checks every guarded read at its end, so a write inside it
     * would fail its own check: callers prove inside within() which of the viewer's notices are visible (returning
     * their ids), then write only those rows of the viewer's own after it.
     */
    public function ownNotice(User $viewer, string $id): DatabaseNotification
    {
        return DatabaseNotification::query()->whereKey($id)
            ->where('notifiable_type', $viewer->getMorphClass())->where('notifiable_id', $viewer->id)->firstOrFail();
    }

    /** @param list<string> $ids */
    public function markOwnRead(User $viewer, array $ids): int
    {
        $written = 0;
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $written += DatabaseNotification::query()->whereIn('id', $chunk)->whereNull('read_at')
                ->where('notifiable_type', $viewer->getMorphClass())->where('notifiable_id', $viewer->id)
                ->update(['read_at' => now()]);
        }

        return $written;
    }

    /** After reading a private exposure notice: confirm only its read flag was kept (the follow-up stays open). */
    public function confirmRead(DatabaseNotification $row, User $viewer): void
    {
        if ($row->type !== ChemicalExposureFollowupAssignedNotification::class) { return; }
        $this->within($viewer, function (?HealthSafetyReadContext $context) use ($row, $viewer): bool {
            if ($context === null) { throw new LogicException('A private follow-up notice needs its H&S read context.'); }
            $this->exposures->assertReadPersisted($row->fresh() ?? $row, $viewer, $context);

            return true;
        });
    }

    private function own(User $viewer): QueryBuilder
    {
        return DB::table('notifications')->where('notifiable_type', $viewer->getMorphClass())->where('notifiable_id', $viewer->id);
    }
}
