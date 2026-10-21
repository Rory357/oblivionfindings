<?php

namespace App\Services\Medication\Followups;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\ShiftCancellationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * eMAR audit Lane C5 (EA-070, EA-071, EA-165, EA-167): a shift cancelled
 * after doses were recorded on it. The dose records are clinical evidence
 * and are never rewritten — no notes appended, no review flag or flagger
 * overwritten. Instead each dose recorded on the shift (its effective
 * record) gets ONE house-lead follow-up, "Review a dose from a cancelled
 * shift", due by the end of the next shift (the dose-review rule), and a P09
 * "dose.review_flagged" event.
 *
 * ShiftCancellationService::cancel(), ShiftSeriesController::cancelFuture()
 * and RespiteShiftSync::syncBooking() (C5b) call this LAST in their transaction:
 * nothing here writes a dose record, so no medication row locks are taken,
 * and the P09 head lock is the transaction's final lock.
 */
final class CancelledShiftDoseReview
{
    public const TYPE = 'shift_cancelled';

    public const SOURCE = 'shift-cancelled';

    public function __construct(
        private readonly MedicationFollowupService $followups,
        private readonly MedicationEventRecorder $events,
    ) {}

    /** @return int the follow-ups raised */
    public function raise(Shift $shift, ?User $actor): int
    {
        return $this->raiseMany([$shift], $actor);
    }

    /**
     * C5b: several shifts cancelled in one transaction (a series' future
     * occurrences) — one P09 append for all of them, still the last lock.
     *
     * @param  iterable<Shift>  $shifts
     * @return int the follow-ups raised
     */
    public function raiseMany(iterable $shifts, ?User $actor): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Raise cancelled-shift dose reviews inside the cancellation transaction.');
        }
        $shiftIds = collect($shifts)->map(fn (Shift $shift): int => (int) $shift->id)->unique()->values()->all();
        if ($shiftIds === []) {
            return 0;
        }
        $records = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->whereIn('shift_id', $shiftIds)
            ->whereIn('status', ScheduledDoseStates::RECORDED_STATUSES)
            ->whereNotNull('client_medication_id')
            ->orderBy('id')
            ->get();
        $events = [];
        $raised = 0;
        foreach ($records as $record) {
            $client = Client::query()->find($record->client_id);
            $order = ClientMedication::withTrashed()->find($record->client_medication_id);
            if (! $client instanceof Client || ! $order instanceof ClientMedication || (int) $order->client_id !== (int) $client->id) {
                continue;
            }
            $row = $this->followups->ensureForSource(
                self::SOURCE, $record->shift_id.'-'.$record->id, $client, $order, $record, null,
                $this->followups->doseReviewDueAt($client, $record),
                [
                    'reason' => ShiftCancellationService::MEDICATION_REVIEW_REASON,
                    'shift_id' => (int) $record->shift_id,
                    'cancelled_by' => $actor?->id,
                    'cancelled_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ],
            );
            if (! $row->wasRecentlyCreated) {
                continue;
            }
            $raised++;
            if ((int) $client->site_id > 0) {
                $events[] = new MedicationEventData(
                    siteId: (int) $client->site_id, kind: 'dose.review_flagged',
                    subjectType: 'medication_administration', subjectId: (string) $record->id,
                    actorId: $actor?->id, occurredAt: CarbonImmutable::now('UTC'),
                    summary: 'Dose recorded on a cancelled shift flagged for review.',
                    facts: ['reason' => 'shift_cancelled', 'shift_id' => (int) $record->shift_id, 'followup_id' => (int) $row->id],
                    clientId: (int) $client->id, controlled: (bool) $order->controlled_drug,
                );
            }
        }
        // P09 last: its head lock is the final lock of the cancellation.
        $this->events->appendMany($events);

        return $raised;
    }
}
