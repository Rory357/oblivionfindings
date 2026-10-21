<?php

namespace App\Services\Emar;

use App\Models\Client;
use App\Models\MedicationFollowup;
use App\Models\ShiftHandover;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\MarScheduleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * eMAR audit Lane C5 (EA-034): "Discrepancy found" on the handover's
 * witnessed controlled-drug count reaches the register. The house lead gets
 * ONE follow-up per handover, due now, with what the two workers found and a
 * link to the person's controlled-drug register, where the lead does the
 * per-medicine recount that opens the register's own discrepancy. A later
 * replacement count that still doesn't match updates the open follow-up; one
 * after the lead signed it off opens the next.
 *
 * Safety floor (not a setting): only staff with both controlled-drug
 * capabilities see or sign it off (MedicationFollowupService).
 *
 * Called from ShiftHandoverService::save() and ::applyEdit(), inside their
 * transactions, right after the governed count is audited. No P09 event:
 * the handover's own audit row records the count, and the P09 head lock must
 * stay the last lock of the transactions that write one.
 */
final class HandoverControlledCountFollowup
{
    public const TYPE = 'cd_handover_discrepancy';

    public const LABEL = 'Controlled-drug count didn’t match at handover';

    public function __construct(
        private readonly MedicationFollowupService $followups,
        private readonly MarScheduleService $schedule,
    ) {}

    /**
     * @param  array{result?: string, notes?: string|null, witness_name?: string|null, verified_by_name?: string|null, verified_at?: string|null, witness_id?: int|null}|null  $verification
     */
    public function afterCount(ShiftHandover $handover, Client $client, ?array $verification): void
    {
        if (($verification['result'] ?? null) !== 'discrepancy') {
            return;
        }
        $at = filled($verification['verified_at'] ?? null)
            ? Carbon::parse((string) $verification['verified_at'])->timezone($this->schedule->workerTimezone())
            : now()->timezone($this->schedule->workerTimezone());
        $context = [
            'label' => self::LABEL,
            'reason' => sprintf(
                'At handover on %s, %s and %s counted the controlled drugs and the count didn’t match the register. Their notes: %s',
                $at->format('j M Y, g:i a'),
                (string) ($verification['verified_by_name'] ?? 'the outgoing worker'),
                (string) ($verification['witness_name'] ?? 'the witness'),
                trim((string) ($verification['notes'] ?? '')),
            ),
            'handover_id' => (int) $handover->id,
            'shift_id' => (int) $handover->outgoing_shift_id,
            'counted_at' => $at->copy()->utc()->toIso8601String(),
            'counted_by' => isset($verification['verified_by']) ? (int) $verification['verified_by'] : null,
            'witness_id' => isset($verification['witness_id']) ? (int) $verification['witness_id'] : null,
            'source_url' => '/emar/controlled?site_id='.(int) $client->site_id.'&client_id='.(int) $client->id,
        ];

        $base = 'cd-handover:'.$handover->id;
        $rows = MedicationFollowup::query()->where('type', self::TYPE)
            ->where(fn ($keys) => $keys->where('source_key', $base)->orWhere('source_key', 'like', $base.':%'))
            ->orderByDesc('id')->get(['id', 'source_key', 'completed_at']);
        $open = $rows->first(fn (MedicationFollowup $row): bool => $row->completed_at === null);
        if ($open !== null) {
            $this->followups->updateSourceContext($open->source_key, $context);

            return;
        }
        $key = $rows->isEmpty() ? $base : $base.':'.($rows->count() + 1);
        $this->followups->ensure($key, self::TYPE, $client, null, null, null, CarbonImmutable::now('UTC'), $context);
    }
}
