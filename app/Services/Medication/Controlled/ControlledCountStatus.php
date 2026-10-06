<?php

namespace App\Services\Medication\Controlled;

use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** One organisation count policy and canonical witnessed evidence for all projections. */
final class ControlledCountStatus
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly ControlledPolicy $policy,
    ) {}

    /** Latest actual count time; the row ID only breaks ties at the same instant. */
    public function latestWitnessedCounts(iterable $medicationIds, ?array $siteIds = null): Collection
    {
        $base = $this->scope->scopeCanonicalClientMedicationRows(
            ClientControlledDrugEntry::query()->where('entry_type', 'balance_check')
                ->whereNotNull('witnessed_by')->where('recorded_at', '<=', now()->utc())
                ->whereIn('client_medication_id', collect($medicationIds)->all()),
            $siteIds, false,
        );
        $times = (clone $base)->selectRaw('client_medication_id AS medicine_id, MAX(recorded_at) AS latest_at')
            ->groupBy('client_medication_id');
        $heads = (clone $base)->joinSub($times, 'count_times', fn ($join) => $join->on('count_times.medicine_id', '=', 'client_controlled_drug_entries.client_medication_id')
            ->on('count_times.latest_at', '=', 'client_controlled_drug_entries.recorded_at'))
            ->selectRaw('MAX(client_controlled_drug_entries.id) AS latest_id')
            ->groupBy('client_controlled_drug_entries.client_medication_id')->pluck('latest_id');

        return ClientControlledDrugEntry::query()->whereIn('id', $heads)->get()->keyBy('client_medication_id');
    }

    /** Approved sites, with historical stock retained until physically cleared. */
    public function relevantMedicines(?int $siteId = null, bool $currentRead = false): Collection
    {
        return ClientMedication::withTrashed()->controlled()
            ->whereHas('client.site', fn ($site) => $site->where('is_active', true)
                ->where(fn ($q) => $q->where('archived', false)->orWhereNull('archived'))->whereNull('archived_at'))
            ->when($siteId !== null, fn ($q) => $q->whereHas('client', fn ($client) => $client->where('site_id', $siteId)))
            ->where(fn ($q) => $q->where(fn ($active) => $active->active())
                // The outer medicine lock does not make this subquery a current
                // read. A committed historical correction can restore stock
                // after publication has established its older read view.
                ->orWhereHas('stock', fn ($stock) => $stock->where('on_hand', '>', 0)
                    ->when($currentRead, fn ($query) => $query->lock('for share nowait'))))
            ->with(['client.site', 'stock' => fn ($query) => $currentRead ? $query->lock('for share nowait') : $query])
            ->when($currentRead, fn ($query) => $query->lock('for share nowait'))->get();
    }

    /**
     * Publication supplies its locked policy values, rather than an older
     * implicit settings snapshot. Current medicine/stock reads retain scope;
     * count evidence is append-only, so missing a concurrent completion only
     * conservatively asks the publisher to retry.
     *
     * @param  array{day: int, time: string}  $anchor
     */
    public function hasUnfinishedWeeklyCounts(array $anchor, ?DateTimeInterface $effectiveAt, int $overdueMinutes): bool
    {
        try {
            // Publication already holds actor locks. Never wait for a medicine
            // or stock lock held by a writer that will next require that actor.
            $medicines = $this->relevantMedicines(currentRead: true);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'controlled_counts.weekly_anchor' => 'Counts are being recorded now. Wait for that work to finish, then review this change again.',
            ]);
        }
        $counts = $this->latestWitnessedCounts($medicines->pluck('id'));
        $clock = CarbonImmutable::now('UTC');
        foreach ($medicines as $medicine) {
            if (! $this->policy->countRequired($medicine)) {
                continue;
            }
            $notBefore = $effectiveAt;
            if ($medicine->created_at !== null && ($notBefore === null || $medicine->created_at->greaterThan($notBefore))) {
                $notBefore = $medicine->created_at;
            }
            $status = $this->policy->countStatusForWeeklyAnchor($clock, $counts->get($medicine->id)?->recorded_at,
                $anchor, $overdueMinutes, config('app.worker_timezone', 'Pacific/Auckland'), $notBefore);
            if (in_array($status['status'], ['due', 'overdue'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, ClientMedication> */
    public function dueMedicines(?int $siteId = null, bool $overdueOnly = false): Collection
    {
        $medicines = $this->relevantMedicines($siteId);
        $counts = $this->latestWitnessedCounts($medicines->pluck('id'), $siteId !== null ? [$siteId] : null);

        return $medicines->filter(fn (ClientMedication $medicine) => in_array($this->policy->countStatus($medicine, now(), $counts->get($medicine->id)?->recorded_at)['status'],
            $overdueOnly ? ['overdue'] : ['due', 'overdue'], true))->values();
    }

    public function overdueMedicines(?int $siteId = null): Collection
    {
        return $this->dueMedicines($siteId, overdueOnly: true);
    }

    /** Retire stale projections without changing medicine or count evidence. */
    public function refreshDashboardAlerts(?int $siteId = null): Collection
    {
        $overdue = $this->overdueMedicines($siteId);
        MedicationDashboardAlert::query()->where('alert_type', 'controlled_overdue_check')->where('status', 'active')
            ->when($siteId !== null, fn ($q) => $q->whereHas('client', fn ($client) => $client->where('site_id', $siteId)))
            ->where(fn ($q) => $q->whereNotIn('client_medication_id', $overdue->pluck('id'))->orWhereNull('client_medication_id'))
            ->get()->each(fn (MedicationDashboardAlert $alert) => $alert->resolve('The configured count policy no longer reports this medicine overdue.'));
        foreach ($overdue as $medicine) {
            $person = trim($medicine->client->first_name.' '.$medicine->client->last_name);
            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $medicine->client_id, alertType: 'controlled_overdue_check', severity: 'warning',
                message: "{$medicine->name} for {$person}: the configured controlled-drug count is overdue.",
                medicationId: $medicine->id,
            );
        }

        return $overdue;
    }
}
