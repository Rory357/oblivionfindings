<?php

namespace App\Services\Medication;

use App\Models\ClientMedicationAdministration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * When repeated refusals escalate (eMAR P11 Rounds & timing): this many
 * refusals or withholds of the same medicine for the same person, recorded
 * within this many days, go to a manager and the GP.
 *
 * The one definition. Recording a refusal follow-up (which escalates it and
 * raises the incident) and the daily alert to team leads both ask here, so
 * they can never disagree about what counts.
 */
class RefusalEscalationPolicy
{
    /** What counts: a refusal, or a dose withheld. */
    public const STATUSES = ['refused', 'withheld'];

    public function __construct(private readonly DoseTimingSettings $timing) {}

    public function threshold(): int
    {
        return $this->timing->refusalEscalationCount();
    }

    public function days(): int
    {
        return $this->timing->refusalEscalationDays();
    }

    /** The earliest record time that still counts. */
    public function since(): CarbonInterface
    {
        return now()->subDays($this->days());
    }

    /**
     * Effective refusals and withholds recorded within the escalation period,
     * for every person and medicine. A record counts from when it was
     * recorded; an older record with no recorded time counts from its dose
     * time.
     *
     * @return Builder<ClientMedicationAdministration>
     */
    public function recent(): Builder
    {
        $since = $this->since();

        return ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->whereIn('status', self::STATUSES)
            ->where(fn (Builder $recorded) => $recorded
                ->where('administered_at', '>=', $since)
                ->orWhere(fn (Builder $untimed) => $untimed
                    ->whereNull('administered_at')
                    ->where('scheduled_for', '>=', $since)));
    }

    /** Refusals and withholds of one medicine for one person within the period. */
    public function countFor(int $clientId, int $clientMedicationId): int
    {
        return $this->recent()
            ->where('client_id', $clientId)
            ->where('client_medication_id', $clientMedicationId)
            ->count();
    }

    public function escalates(int $count): bool
    {
        return $count >= $this->threshold();
    }
}
