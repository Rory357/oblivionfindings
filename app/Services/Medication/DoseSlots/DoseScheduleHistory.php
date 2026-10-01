<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Models\MedicationDoseOrderPause;
use App\Models\MedicationDoseScheduleVersion;
use Carbon\CarbonImmutable;

/**
 * Records what the dose-slot rules need from order changes, in the same
 * transaction as the change (called from ClientMedication's model events):
 *
 * - every verification-sensitive change writes a new schedule version, which
 *   takes effect only when it is verified (the previous one stays in effect);
 * - verification and rejection stamp the version awaiting them;
 * - pausing and resuming open and close a pause.
 *
 * An order created before C3 has no versions; its first change first records
 * the values it had (its baseline), so history is never rewritten.
 */
final class DoseScheduleHistory
{
    /** Order changes that can alter which slots are owed. */
    public const SLOT_RELEVANT_FIELDS = [
        'dose_times', 'frequency', 'start_date', 'end_date', 'is_prn',
        'approval_status', 'verified_at', 'state', 'active', 'paused_at',
        'ceased_at', 'superseded_by', 'deleted_at', 'controlled_drug',
    ];

    public function created(ClientMedication $order): void
    {
        $version = DoseOrderTimelineFactory::currentRowVersion($order);
        $this->insertVersion($order, $version->changedAt, $version->verifiedAt, $version->rejectedAt);

        if (DoseOrderTimelineFactory::isPausedNow($order)) {
            MedicationDoseOrderPause::query()->create([
                'client_medication_id' => $order->id,
                'paused_at' => DoseOrderTimelineFactory::rawInstant($order->getAttributes()['paused_at'] ?? null)
                    ?? $version->changedAt,
            ]);
        }
    }

    public function updated(ClientMedication $order, CarbonImmutable $now): void
    {
        $changes = $order->getChanges();
        $sensitiveChanged = array_intersect_key($changes, array_flip(ClientMedication::verificationSensitiveFields())) !== [];

        if ($sensitiveChanged || array_key_exists('approval_status', $changes) || array_key_exists('verified_at', $changes)) {
            $this->ensureBaseline($order);
        }

        $status = $order->approval_status;
        if ($sensitiveChanged) {
            // A new version of the order. The model sends every such change
            // back for verification, so it waits unless already verified.
            $verified = $status === null || $status === 'verified';
            $this->insertVersion(
                $order,
                $now,
                $verified ? (DoseOrderTimelineFactory::rawInstant($order->getAttributes()['verified_at'] ?? null) ?? $now) : null,
                $status === 'rejected' ? $now : null,
            );
        } elseif (array_key_exists('approval_status', $changes)) {
            $pending = $this->latestAwaitingVersion($order);
            if ($status === 'verified' && $pending !== null) {
                $pending->update([
                    'verified_at' => DoseOrderTimelineFactory::rawInstant($order->getAttributes()['verified_at'] ?? null) ?? $now,
                ]);
            } elseif ($status === 'rejected' && $pending !== null) {
                $pending->update(['rejected_at' => $now]);
            } elseif ($status === 'pending_verification' && $pending === null) {
                // Sent back for verification without a field change.
                $this->insertVersion($order, $now, null, null);
            }
        }

        if (array_intersect_key($changes, array_flip(['state', 'active', 'paused_at', 'ceased_at'])) !== []) {
            $this->recordPauseState($order, $now);
        }
    }

    private function recordPauseState(ClientMedication $order, CarbonImmutable $now): void
    {
        $open = MedicationDoseOrderPause::query()
            ->where('client_medication_id', $order->id)
            ->whereNull('resumed_at')
            ->latest('paused_at')
            ->latest('id')
            ->first();
        $paused = DoseOrderTimelineFactory::isPausedNow($order);

        if ($paused && $open === null) {
            $pausedAt = DoseOrderTimelineFactory::rawInstant($order->getAttributes()['paused_at'] ?? null);
            MedicationDoseOrderPause::query()->create([
                'client_medication_id' => $order->id,
                // A pause can't start in the future or before history began.
                'paused_at' => $pausedAt !== null && $pausedAt->lessThanOrEqualTo($now) ? $pausedAt : $now,
            ]);
        } elseif (! $paused && $open !== null) {
            $open->update(['resumed_at' => $now]);
        }
    }

    private function ensureBaseline(ClientMedication $order): void
    {
        if (MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->exists()) {
            return;
        }

        $baseline = DoseOrderTimelineFactory::currentRowVersion($order, original: true);
        $this->insertVersion($order, $baseline->changedAt, $baseline->verifiedAt, $baseline->rejectedAt, original: true);
    }

    private function latestAwaitingVersion(ClientMedication $order): ?MedicationDoseScheduleVersion
    {
        return MedicationDoseScheduleVersion::query()
            ->where('client_medication_id', $order->id)
            ->whereNull('verified_at')
            ->whereNull('rejected_at')
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function insertVersion(
        ClientMedication $order,
        CarbonImmutable $changedAt,
        ?CarbonImmutable $verifiedAt,
        ?CarbonImmutable $rejectedAt,
        bool $original = false,
    ): void {
        $version = DoseOrderTimelineFactory::currentRowVersion($order, $original);

        MedicationDoseScheduleVersion::query()->create([
            'client_medication_id' => $order->id,
            'dose_times' => $version->doseTimes,
            'start_date' => $version->startDate,
            'end_date' => $version->endDate,
            'is_prn' => $version->isPrn,
            'self_managed' => false,
            'changed_at' => $changedAt->utc()->format('Y-m-d H:i:s'),
            'verified_at' => $verifiedAt?->utc()->format('Y-m-d H:i:s'),
            'rejected_at' => $verifiedAt === null ? $rejectedAt?->utc()->format('Y-m-d H:i:s') : null,
        ]);
    }
}
