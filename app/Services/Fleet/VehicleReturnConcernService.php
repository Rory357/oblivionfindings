<?php

namespace App\Services\Fleet;

use App\Models\FleetVehicleBooking;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A vehicle returned with a condition concern gets linked Maintenance work
 * through the PKG-01 report path: approved Site routing, the Coordinator
 * assesses, and the booking stays the report's source. The return itself
 * never depends on it: when the Site has no approved routing, or the person
 * can't report Maintenance work for the vehicle, the concern stays on the
 * booking and the caller is told no work was created. One booking gets at
 * most one record, however often its return is retried.
 */
class VehicleReturnConcernService
{
    /** The return wizard's "Concern recorded" condition. */
    public const CONCERN = 'Concern recorded';

    public const REPORTED_ACTION = 'fleet.booking.return_concern.reported';

    public const NOT_ROUTED_ACTION = 'fleet.booking.return_concern.not_routed';

    public const NOT_ROUTED = 'The return is recorded, but no Maintenance work was created: this vehicle\'s site needs an approved Maintenance Coordinator and backup. Report the concern in Maintenance once routing is approved.';

    public const NOT_PERMITTED = 'The return is recorded, but no Maintenance work was created: you can\'t report Maintenance work for this vehicle. Ask a maintenance coordinator to record the concern.';

    public function __construct(
        private readonly MaintenanceAccessService $access,
        private readonly MaintenanceReportService $reports,
    ) {}

    public static function isConcern(?string $condition): bool
    {
        return $condition !== null && mb_strtolower(trim($condition)) === mb_strtolower(self::CONCERN);
    }

    /**
     * Linked work for a returned booking's concern. Null when the booking has
     * no concern recorded at return.
     *
     * @return array{status:string,work_order_id:int,reference:?string}|array{status:string,message:string}|null
     */
    public function report(User $actor, FleetVehicleBooking $booking): ?array
    {
        if ($booking->status !== 'returned' || ! self::isConcern($booking->condition_on_return)) {
            return null;
        }
        $current = User::query()->findOrFail($actor->id);
        if (! $this->access->canReport($current)) {
            return $this->notRouted($current, $booking, self::NOT_PERMITTED);
        }

        try {
            return DB::transaction(function () use ($current, $booking): array {
                // The vehicle first (the report path's lock order), so a retried
                // or concurrent return finds the work already created.
                $asset = $this->access->asset($current, (int) $booking->asset_id, true);
                $existing = DB::table('fleet_maintenance_reports')->where('asset_id', $asset->id)
                    ->where('source_type', 'fleet_vehicle_booking')->where('source_id', $booking->id)
                    ->whereNull('duplicate_of_report_id')->orderBy('id')->lockForUpdate()->value('work_order_id');
                if ($existing !== null) {
                    return $this->created(FleetWorkOrder::query()->findOrFail((int) $existing));
                }
                $notes = trim((string) $booking->return_notes);
                $evidence = trim((string) $booking->return_evidence_reference);
                $order = $this->reports->submit($current, [
                    'asset_id' => (int) $asset->id,
                    'title' => 'Return condition concern',
                    'description' => implode("\n", array_filter([
                        'Concern recorded when '.($booking->reference_number ?: 'booking #'.$booking->id).' was returned.',
                        $notes !== '' ? 'Return notes: '.$notes : null,
                        $evidence !== '' ? 'Condition check / evidence: '.$evidence : null,
                    ])),
                    'priority' => 'medium',
                    'observed_at' => $booking->returned_at?->copy()->utc()->format('Y-m-d H:i:s'),
                    'source_type' => 'fleet_vehicle_booking',
                    'source_id' => (int) $booking->id,
                    // Derived from the booking: a retried return never opens a second record.
                    'request_key' => 'booking-return-concern-'.$booking->id,
                ]);
                AuditLogger::logOrFail(self::REPORTED_ACTION, $booking, [
                    'actor_id' => $current->id, 'booking_id' => $booking->id, 'asset_id' => $asset->id,
                    'work_order_id' => $order->id,
                    'reason' => 'Linked Maintenance work '.($order->reference_number ?: '#'.$order->id).' created for the concern.',
                ]);

                return $this->created($order);
            }, 3);
        } catch (ValidationException) {
            // The report path refuses a Site without an approved Coordinator and backup.
            return $this->notRouted($current, $booking, self::NOT_ROUTED);
        } catch (ModelNotFoundException|HttpExceptionInterface) {
            // The vehicle isn't at one of the person's approved Maintenance Sites.
            return $this->notRouted($current, $booking, self::NOT_PERMITTED);
        }
    }

    /** @return array{status:string,work_order_id:int,reference:?string} */
    private function created(FleetWorkOrder $order): array
    {
        return ['status' => 'created', 'work_order_id' => (int) $order->id, 'reference' => $order->reference_number];
    }

    /**
     * The concern stays on the booking. Its history says so once; a retried
     * return with the same outcome doesn't repeat the entry.
     *
     * @return array{status:string,message:string}
     */
    private function notRouted(User $current, FleetVehicleBooking $booking, string $message): array
    {
        $latest = DB::table('audit_logs')->where('auditable_type', $booking->getMorphClass())
            ->where('auditable_id', $booking->id)->whereIn('action', [self::REPORTED_ACTION, self::NOT_ROUTED_ACTION])
            ->orderByDesc('id')->first(['action', 'meta']);
        $told = $latest !== null && $latest->action === self::NOT_ROUTED_ACTION
            && ((json_decode((string) $latest->meta, true) ?: [])['reason'] ?? null) === $message;
        if (! $told) {
            AuditLogger::logOrFail(self::NOT_ROUTED_ACTION, $booking, [
                'actor_id' => $current->id, 'booking_id' => $booking->id, 'asset_id' => (int) $booking->asset_id,
                'reason' => $message,
            ]);
        }

        return ['status' => 'not_routed', 'message' => $message];
    }
}
