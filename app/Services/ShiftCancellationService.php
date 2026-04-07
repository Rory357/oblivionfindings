<?php

namespace App\Services;

use App\Models\ClientMedicationAdministration;
use App\Models\FleetResidentTransport;
use App\Models\FleetVehicleBooking;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftOpenPosition;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftCancellationService
{
    public const TIMESHEET_RETURN_REASON = 'This timesheet is linked to a cancelled shift.';

    public const MEDICATION_REVIEW_REASON = 'This medication record requires review because its linked shift was cancelled.';

    public const MEDICATION_ROUND_REVIEW_REASON = 'This medication round requires review because it includes records from a cancelled shift.';

    public const TRANSPORT_REVIEW_REASON = 'This transport record requires review because its linked shift was cancelled.';

    public const BOOKING_REVIEW_REASON = 'This transport booking requires review because its linked shift was cancelled.';

    public function __construct(
        protected ShiftReplacementService $replacementService,
        protected CoverageReservationService $coverageReservationService,
        protected ShiftTimelineService $timelineService,
    ) {
    }

    /**
     * @return array{
     *     shift: Shift,
     *     already_cancelled: bool,
     *     impact: array<string, array{count:int, ids:array<int,int>}>
     * }
     */
    public function cancel(Shift $shift, ?User $actor = null): array
    {
        return DB::transaction(function () use ($shift, $actor) {
            // Re-fetch with pessimistic lock to prevent race with concurrent complete/cancel.
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($shift->status === 'cancelled') {
                return [
                    'shift' => $shift,
                    'already_cancelled' => true,
                    'impact' => $this->emptyImpact(),
                ];
            }

            if ($shift->status === 'completed') {
                throw ValidationException::withMessages([
                    'shift' => 'Completed shifts are locked and cannot be cancelled.',
                ]);
            }

            $shift->loadMissing([
                'timesheets',
                'medicationAdministrations',
                'residentTransports.booking',
                'incidents',
                'outgoingHandovers:id,outgoing_shift_id,incoming_staff_id,status',
                'client:id,first_name,last_name,site_id',
                'site:id,name,type',
                'staff:id,name',
                'serviceContext:id,name,type',
            ]);

            if ($shift->timesheets->contains(fn (Timesheet $timesheet) => $timesheet->status === 'approved')) {
                throw ValidationException::withMessages([
                    'shift' => 'This shift has an approved timesheet and cannot be cancelled.',
                ]);
            }

            $this->replacementService->cancelActiveForShift($shift, $actor);
            $this->coverageReservationService->releaseForShift($shift);

            ShiftOpenPosition::query()
                ->where('shift_id', $shift->id)
                ->whereIn('status', ['open', 'claimed'])
                ->update(['status' => 'cancelled']);

            $impact = [
                'timesheets' => $this->cascadeTimesheets($shift, $actor),
                'medication_administrations' => ['count' => 0, 'ids' => []],
                'medication_rounds' => ['count' => 0, 'ids' => []],
                'resident_transports' => ['count' => 0, 'ids' => []],
                'fleet_vehicle_bookings' => ['count' => 0, 'ids' => []],
                'incidents' => $this->incidentImpact($shift),
            ];

            $medicationImpact = $this->cascadeMedication($shift, $actor);
            $impact['medication_administrations'] = $medicationImpact['administrations'];
            $impact['medication_rounds'] = $medicationImpact['rounds'];

            $transportImpact = $this->cascadeTransport($shift, $actor);
            $impact['resident_transports'] = $transportImpact['transports'];
            $impact['fleet_vehicle_bookings'] = $transportImpact['bookings'];

            $shift->update([
                'status' => 'cancelled',
                'actual_starts_at' => null,
                'actual_ends_at' => null,
            ]);

            $freshShift = $shift->fresh([
                'client:id,first_name,last_name,site_id',
                'site:id,name,type',
                'staff:id,name',
                'serviceContext:id,name,type',
            ]) ?? $shift;

            $this->timelineService->recordCancelled($freshShift, $actor);
            $this->timelineService->recordCancellationCascade($freshShift, $impact, $actor);

            AuditLogger::log('shift.cancel.cascade', $freshShift, [
                'shift_id' => $freshShift->id,
                'impacts' => $impact,
            ]);

            $this->notifyIncomingHandoverStaff($shift, $freshShift, $actor);

            return [
                'shift' => $freshShift,
                'already_cancelled' => false,
                'impact' => $impact,
            ];
        });
    }

    /**
     * @return array{count:int, ids:array<int,int>}
     */
    protected function cascadeTimesheets(Shift $shift, ?User $actor): array
    {
        $timesheets = $shift->timesheets
            ->whereIn('status', ['draft', 'submitted', 'returned'])
            ->values();

        foreach ($timesheets as $timesheet) {
            $timesheet->forceFill([
                'status' => 'returned',
                'returned_by' => $actor?->id ?? $timesheet->returned_by,
                'returned_at' => now(),
                'returned_notes' => $this->appendReason($timesheet->returned_notes, self::TIMESHEET_RETURN_REASON),
                'approved_by' => null,
                'approved_at' => null,
                'decision_notes' => null,
            ])->save();
        }

        return [
            'count' => $timesheets->count(),
            'ids' => $timesheets->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /**
     * @return array{
     *     administrations: array{count:int, ids:array<int,int>},
     *     rounds: array{count:int, ids:array<int,int>}
     * }
     */
    protected function cascadeMedication(Shift $shift, ?User $actor): array
    {
        $administrationIds = $shift->medicationAdministrations
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($administrationIds === []) {
            return [
                'administrations' => ['count' => 0, 'ids' => []],
                'rounds' => ['count' => 0, 'ids' => []],
            ];
        }

        $administrations = ClientMedicationAdministration::query()
            ->whereKey($administrationIds)
            ->get();

        foreach ($administrations as $administration) {
            $administration->forceFill([
                'review_required' => true,
                'review_reason' => self::MEDICATION_REVIEW_REASON,
                'review_flagged_at' => now(),
                'review_flagged_by' => $actor?->id,
                'notes' => $this->appendReason($administration->notes, self::MEDICATION_REVIEW_REASON),
            ])->saveQuietly();
        }

        $roundIds = $administrations
            ->pluck('medication_round_id')
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($roundIds !== []) {
            $rounds = MedicationRound::query()->whereKey($roundIds)->get();

            foreach ($rounds as $round) {
                $round->forceFill([
                    'review_required' => true,
                    'review_reason' => self::MEDICATION_ROUND_REVIEW_REASON,
                    'review_flagged_at' => now(),
                    'review_flagged_by' => $actor?->id,
                    'notes' => $this->appendReason($round->notes, self::MEDICATION_ROUND_REVIEW_REASON),
                ])->saveQuietly();
            }
        }

        return [
            'administrations' => [
                'count' => count($administrationIds),
                'ids' => $administrationIds,
            ],
            'rounds' => [
                'count' => count($roundIds),
                'ids' => $roundIds,
            ],
        ];
    }

    /**
     * @return array{
     *     transports: array{count:int, ids:array<int,int>},
     *     bookings: array{count:int, ids:array<int,int>}
     * }
     */
    protected function cascadeTransport(Shift $shift, ?User $actor): array
    {
        $transportIds = $shift->residentTransports
            ->filter(fn (FleetResidentTransport $transport) => ! in_array($transport->status, ['completed', 'cancelled'], true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($transportIds === []) {
            return [
                'transports' => ['count' => 0, 'ids' => []],
                'bookings' => ['count' => 0, 'ids' => []],
            ];
        }

        $transports = FleetResidentTransport::query()
            ->with('booking')
            ->whereKey($transportIds)
            ->get();

        foreach ($transports as $transport) {
            $transport->forceFill([
                'review_required' => true,
                'review_reason' => self::TRANSPORT_REVIEW_REASON,
                'review_flagged_at' => now(),
                'review_flagged_by' => $actor?->id,
                'notes' => $this->appendReason($transport->notes, self::TRANSPORT_REVIEW_REASON),
            ])->saveQuietly();
        }

        $bookingIds = $transports
            ->pluck('booking')
            ->filter(fn ($booking) => $booking instanceof FleetVehicleBooking && in_array($booking->status, ['pending', 'approved', 'checked_out'], true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($bookingIds !== []) {
            $bookings = FleetVehicleBooking::query()->whereKey($bookingIds)->get();

            foreach ($bookings as $booking) {
                $booking->forceFill([
                    'review_required' => true,
                    'review_reason' => self::BOOKING_REVIEW_REASON,
                    'review_flagged_at' => now(),
                    'review_flagged_by' => $actor?->id,
                    'notes' => $this->appendReason($booking->notes, self::BOOKING_REVIEW_REASON),
                ])->saveQuietly();
            }
        }

        return [
            'transports' => [
                'count' => count($transportIds),
                'ids' => $transportIds,
            ],
            'bookings' => [
                'count' => count($bookingIds),
                'ids' => $bookingIds,
            ],
        ];
    }

    /**
     * @return array{count:int, ids:array<int,int>}
     */
    protected function incidentImpact(Shift $shift): array
    {
        $incidentIds = $shift->incidents
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'count' => count($incidentIds),
            'ids' => $incidentIds,
        ];
    }

    /**
     * @return array<string, array{count:int, ids:array<int,int>}>
     */
    protected function emptyImpact(): array
    {
        return [
            'timesheets' => ['count' => 0, 'ids' => []],
            'medication_administrations' => ['count' => 0, 'ids' => []],
            'medication_rounds' => ['count' => 0, 'ids' => []],
            'resident_transports' => ['count' => 0, 'ids' => []],
            'fleet_vehicle_bookings' => ['count' => 0, 'ids' => []],
            'incidents' => ['count' => 0, 'ids' => []],
        ];
    }

    protected function notifyIncomingHandoverStaff(Shift $originalShift, Shift $freshShift, ?User $actor): void
    {
        $handovers = $originalShift->relationLoaded('outgoingHandovers')
            ? $originalShift->outgoingHandovers
            : collect();

        $incomingUserIds = $handovers
            ->filter(fn (ShiftHandover $h) => filled($h->incoming_staff_id)
                && in_array($h->status, ['draft', 'submitted'], true))
            ->pluck('incoming_staff_id')
            ->unique()
            ->values()
            ->all();

        if ($incomingUserIds === []) {
            return;
        }

        $siteName = $freshShift->site?->name ?? 'Unknown site';
        $startsAt = $freshShift->starts_at?->format('d M Y H:i') ?? 'unknown time';

        app(NotificationService::class)->notifyCrud(
            $actor,
            'cancelled',
            'shift',
            $freshShift,
            $freshShift->client,
            [
                'kind' => 'operational',
                'subtype' => 'handover',
                'event_key' => 'shifts.handover.cancelled',
                'title' => 'Incoming shift cancelled',
                'body' => "A shift you were scheduled to receive at {$siteName} ({$startsAt}) has been cancelled.",
                'url' => url("/operations/shifts/{$freshShift->id}"),
                'target_user_ids' => $incomingUserIds,
                'include_managers' => false,
                'include_assigned_workers' => false,
                'include_entity_user' => false,
            ],
        );
    }

    protected function appendReason(?string $existing, string $reason): string
    {
        if ($existing && str_contains($existing, $reason)) {
            return $existing;
        }

        return collect([$existing, $reason])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->implode("\n\n");
    }
}
