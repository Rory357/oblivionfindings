<?php

namespace App\Services;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the item list and progress state for the frontline guided round flow.
 *
 * The existing MedicationRound record remains the source of truth. This service
 * only composes "what the worker sees next" — a flat, ordered list of due doses
 * for the round, each annotated with any administration that has already
 * happened in this round (so resume never double-administers).
 */
class GuidedRoundService
{
    public function __construct(
        protected MarScheduleService $scheduleService,
        protected MedicationGovernanceScopeService $governanceScope,
    ) {}

    /**
     * Resolve the ordered list of doses for this round, with any existing
     * administration attached. Items the worker has already acted on are kept
     * in the list so progress counters stay honest and resume is trustworthy.
     *
     * @return array<int, array{
     *     client_id: int,
     *     client_name: string,
     *     client_photo_url: string|null,
     *     medication_id: int,
     *     medication_name: string,
     *     dose: string|null,
     *     route: string|null,
     *     form: string|null,
     *     instructions: string|null,
     *     site_id: int|null,
     *     site_name: string|null,
     *     is_controlled: bool,
     *     is_high_risk: bool,
     *     requires_witness: bool,
     *     requires_blood_glucose: bool,
     *     requires_pulse: bool,
     *     scheduled_for: string,
     *     dose_state: string,
     *     administration: array|null,
     * }>
     */
    public function items(
        MedicationRound $round,
        bool $includeControlled = false,
        ?array $allowedClientIds = null,
        bool $lockForUpdate = false,
    ): array {
        $date = $this->scheduleService->dateFromInput(
            $round->round_date instanceof Carbon
                ? $round->round_date->toDateString()
                : (string) $round->round_date,
        );

        $windowMinutes = max(0, (int) ($round->window_minutes ?? 60));
        $roundTime = $date->copy()->setTimeFromTimeString($round->scheduled_time);
        $windowStart = $roundTime->copy()->subMinutes($windowMinutes);
        $windowEnd = $roundTime->copy()->addMinutes($windowMinutes);

        $allowedClientIds = $allowedClientIds === null
            ? null
            : collect($allowedClientIds)
                ->filter(fn ($clientId): bool => is_numeric($clientId) && (int) $clientId > 0)
                ->map(fn ($clientId): int => (int) $clientId)
                ->unique()
                ->values()
                ->all();

        if (! $this->hasCanonicalScope($round)) {
            return [];
        }

        if ($round->status === 'completed') {
            return $this->completedItems(
                $round,
                $includeControlled,
                $allowedClientIds,
                $windowStart,
                $windowEnd,
            );
        }

        // The round's doses are the dose-slot projection's (P01 C6j): every
        // scheduled dose of the round's people due on the round's day inside
        // its own window — its time ± window_minutes — with the state Meds
        // today shows. (The round's day, as the recording guard requires.)
        // The orders are the ones Meds today lists: verified, or with a change
        // waiting for the order check.
        $medicationQuery = ClientMedication::query()
            ->where(fn ($orders) => $orders->active()->orWhere(fn ($waiting) => $waiting->awaitingVerification()))
            ->when($allowedClientIds !== null, fn ($query) => $query->whereIn('client_id', $allowedClientIds))
            ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
            ->where(function ($q) {
                $q->where('is_prn', false)->orWhereNull('is_prn');
            })
            ->whereHas('client', function ($q) use ($round) {
                if ($round->site_id) {
                    $q->where('site_id', $round->site_id);
                }
                if ($round->service_context_id) {
                    $q->where('service_context_id', $round->service_context_id);
                }
            })
            ->with(['client:id,first_name,last_name,profile_photo_path,site_id', 'client.site:id,name']);
        if ($lockForUpdate) {
            // A locking read is required here: the transaction deliberately
            // takes a non-locking round snapshot before acquiring the canonical
            // Client/medication locks, and MySQL repeatable-read would otherwise
            // reuse that pre-lock snapshot after a concurrent verification.
            $medicationQuery->lockForUpdate();
        }
        $medications = $medicationQuery->get()->keyBy('id');
        $states = app(ScheduledDoseStates::class);
        // With the words for an Away dose, as this reader may read them (C7).
        $doses = $states->withAwayReasons($states->dosesOn($medications, $date, now()), auth()->user());

        // A dose's record wherever it was made — in this round, another, or
        // Meds today — so a dose already recorded is never offered again.
        // This round's own record first when there are two.
        $administrationQuery = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->whereIn('client_medication_id', $medications->keys()->all())
            ->whereIn('status', ScheduledDoseStates::RECORDED_STATUSES)
            ->whereBetween('scheduled_for', [
                $windowStart->copy()->utc()->subMinute(),
                $windowEnd->copy()->utc()->addMinute(),
            ]);
        $this->governanceScope->scopeCanonicalClientMedicationRows(
            $administrationQuery,
            $round->site_id ? [(int) $round->site_id] : null,
            false,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($administrationQuery);
        }
        if ($lockForUpdate) {
            $administrationQuery->lockForUpdate();
        }

        $administrations = $administrationQuery
            ->with(['administeredBy:id,name', 'witnessedBy:id,name'])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (ClientMedicationAdministration $administration): int => (int) $administration->medication_round_id === (int) $round->id ? 0 : 1)
            ->unique(fn (ClientMedicationAdministration $administration): string => $this->recordKey($administration))
            ->keyBy(fn (ClientMedicationAdministration $administration): string => $this->recordKey($administration));

        $items = new Collection;

        foreach ($doses as $orderId => $orderDoses) {
            $med = $medications->get($orderId);
            foreach ($orderDoses as $dose) {
                $scheduled = $dose['due_at'];
                if (! $scheduled->between($windowStart, $windowEnd, true)) {
                    continue;
                }

                $admin = $administrations->get($med->id.':'.$scheduled->copy()->utc()->format('Y-m-d H:i'));
                // An order waiting for its check can't be recorded against
                // until it is checked, whatever its dose's own state; a dose
                // due while the person is away (C7) is away either way.
                $doseState = ScheduledDoseStates::listStatus($dose);
                if (! $med->isAdministrable() && $doseState !== DoseSlotProjection::STATE_AWAY) {
                    $doseState = DoseSlotProjection::STATE_PENDING_CHECK;
                }

                $items->push($this->formatItem(
                    $med,
                    $scheduled,
                    $admin,
                    $doseState,
                    $doseState === DoseSlotProjection::STATE_AWAY ? $dose['away_reason'] : null,
                    $doseState === DoseSlotProjection::STATE_AWAY ? ($dose['away']['source'] ?? null) : null,
                ));
            }
        }

        return $items
            ->sortBy([
                ['scheduled_for', 'asc'],
                ['client_name', 'asc'],
                ['medication_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Progress summary derived from the live items list.
     */
    public function progress(
        MedicationRound $round,
        bool $includeControlled = false,
        ?array $allowedClientIds = null,
    ): array {
        return $this->summarise($this->items($round, $includeControlled, $allowedClientIds));
    }

    /**
     * Decide whether the shared round can be completed without projecting any
     * hidden Client or controlled-medication detail to the caller.
     *
     * Viewer filters are deliberately ignored: completion closes the whole
     * stored Site/context round, so every canonical scheduled item must already
     * have effective administration evidence. Completed rows stay replay-safe.
     */
    public function canCompleteCanonicalRound(MedicationRound $round): bool
    {
        return $this->canonicalCompletionDecision($round, false);
    }

    /**
     * Re-read the canonical dose and administration rows as locking/current
     * reads while the scope service holds the round-membership aggregate.
     */
    public function canCompleteCanonicalRoundUnderLock(MedicationRound $round): bool
    {
        return $this->canonicalCompletionDecision($round, true);
    }

    private function canonicalCompletionDecision(MedicationRound $round, bool $lockProjection): bool
    {
        if (! $this->hasCanonicalScope($round)) {
            return false;
        }

        if ($round->status === 'completed') {
            return true;
        }

        if ($round->status !== 'in_progress') {
            return false;
        }

        $items = $lockProjection
            ? $this->items($round, true, null, true)
            : $this->items($round, true);

        return $this->summarise($items)['pending'] === 0;
    }

    /**
     * @param  array<int, array>  $items
     */
    public function summarise(array $items): array
    {
        $given = 0;
        $refused = 0;
        $held = 0;
        $pending = 0;
        $waiting = 0;
        $away = 0;
        $nextIndex = null;

        foreach ($items as $idx => $item) {
            $status = $item['administration']['status'] ?? null;
            if ($status === 'given') {
                $given++;
            } elseif ($status === 'refused') {
                $refused++;
            } elseif ($status === 'withheld' || $status === 'missed') {
                $held++;
            } elseif (($item['dose_state'] ?? null) === DoseSlotProjection::STATE_PENDING_CHECK) {
                // Waiting for the order check: shown, not recordable until
                // the order is checked — Meds today and the overdue alerts
                // chase it once checked.
                $waiting++;
            } elseif (($item['dose_state'] ?? null) === DoseSlotProjection::STATE_AWAY) {
                // Away (C7): not owed here while the person is away.
                $away++;
            } else {
                $pending++;
                if ($nextIndex === null) {
                    $nextIndex = $idx;
                }
            }
        }

        // The doses owed in the round (Main, 3 Oct): one waiting for the
        // order check, or due while the person is away, isn't — it is left out
        // of the total and the percent and counted on its own, so a round
        // with only those left is 100% recorded. The rounds board's
        // roundCounts() uses the same rule.
        $total = count($items) - $waiting - $away;
        $completed = $given + $refused + $held;

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'given' => $given,
            'refused' => $refused,
            'held' => $held,
            'next_index' => $nextIndex,
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : ($waiting + $away > 0 ? 100 : 0),
            'waiting' => $waiting,
            'away' => $away,
        ];
    }

    /**
     * Flatten the round's doses into "cells" for the Resident × Round chart and
     * the per-round audit timeline. Reuses items() (one schedule pipeline) and
     * hoists the administration outcome onto each cell. An un-actioned dose is
     * "due", "overdue" once its window has ended, or "pending_check" while
     * its order's change waits for the order check — as Meds today shows it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cells(
        MedicationRound $round,
        bool $includeControlled = false,
        ?array $allowedClientIds = null,
    ): array {
        return array_map(function (array $it): array {
            $admin = $it['administration'] ?? null;

            return [
                'resident_id' => $it['client_id'],
                'resident_name' => $it['client_name'],
                'site_id' => $it['site_id'],
                'site_name' => $it['site_name'],
                'medication_id' => $it['medication_id'],
                'medication_name' => $it['medication_name'],
                'dose' => $it['dose'],
                'route' => $it['route'],
                'is_controlled' => $it['is_controlled'],
                'is_high_risk' => $it['is_high_risk'],
                'requires_witness' => $it['requires_witness'],
                'requires_blood_glucose' => $it['requires_blood_glucose'],
                'requires_pulse' => $it['requires_pulse'],
                'scheduled_for' => $it['scheduled_for'],
                'status' => $admin['status'] ?? match ($it['dose_state'] ?? null) {
                    'overdue', DoseSlotProjection::STATE_PENDING_CHECK, DoseSlotProjection::STATE_AWAY => $it['dose_state'],
                    default => 'due',
                },
                'away_reason' => $admin === null ? ($it['away_reason'] ?? null) : null,
                'witnessed_by' => $admin['witnessed_by'] ?? null,
                'blood_glucose_level' => $admin['blood_glucose_level'] ?? null,
                'pulse_bpm' => $admin['pulse_bpm'] ?? null,
                'reason' => $admin['reason'] ?? null,
                'reason_code' => $admin['reason_code'] ?? null,
                'administered_at' => $admin['administered_at'] ?? null,
                'administered_by' => $admin['administered_by'] ?? null,
            ];
        }, $this->items($round, $includeControlled, $allowedClientIds));
    }

    /**
     * Whether a dose should prompt a blood-glucose reading before "Given".
     * TODO(G4): replace this name heuristic with a real client_medications flag.
     */
    private function requiresBloodGlucose(ClientMedication $med): bool
    {
        return (bool) preg_match('/insulin|novorapid|lantus|humalog|actrapid|levemir/i', (string) $med->name);
    }

    /**
     * Whether a dose should prompt an apical-pulse reading before "Given".
     * TODO(G4): replace this name heuristic with a real client_medications flag.
     */
    private function requiresPulse(ClientMedication $med): bool
    {
        return (bool) preg_match('/digoxin/i', (string) $med->name);
    }

    private function hasCanonicalScope(MedicationRound $round): bool
    {
        $siteId = is_numeric($round->site_id) && (int) $round->site_id > 0
            ? (int) $round->site_id
            : null;
        if ($siteId === null || ! Site::query()
            ->whereKey($siteId)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->exists()) {
            return false;
        }

        if ($round->service_context_id === null) {
            return true;
        }

        $serviceContextId = is_numeric($round->service_context_id) && (int) $round->service_context_id > 0
            ? (int) $round->service_context_id
            : null;

        return $serviceContextId !== null
            && ServiceContext::query()
                ->availableToSite($siteId)
                ->whereKey($serviceContextId)
                ->where('is_active', true)
                ->exists();
    }

    /** @return array<int, array<string, mixed>> */
    private function completedItems(
        MedicationRound $round,
        bool $includeControlled,
        ?array $allowedClientIds,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): array {
        // The round's own records, and — as while it was open (C6j) — the
        // doses in its window recorded outside any round before it was
        // completed (from Meds today, say).
        $query = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where(function ($records) use ($round) {
                $records->where('medication_round_id', $round->id)
                    ->orWhere(function ($unlinked) use ($round) {
                        $unlinked->whereNull('medication_round_id')
                            ->whereIn('status', ScheduledDoseStates::RECORDED_STATUSES)
                            ->when($round->completed_at !== null, fn ($before) => $before->where('created_at', '<=', $round->completed_at));
                    });
            })
            ->whereBetween('scheduled_for', [
                $windowStart->copy()->utc(),
                $windowEnd->copy()->utc(),
            ])
            ->when($allowedClientIds !== null, fn ($query) => $query->whereIn('client_id', $allowedClientIds))
            ->when($round->service_context_id !== null, fn ($query) => $query->whereHas(
                'client',
                fn ($client) => $client->where('service_context_id', $round->service_context_id),
            ));
        $this->governanceScope->scopeCanonicalClientMedicationRows(
            $query,
            [(int) $round->site_id],
            false,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        $items = $query
            ->with([
                'medication.client:id,first_name,last_name,profile_photo_path,site_id',
                'medication.client.site:id,name',
                'administeredBy:id,name',
                'witnessedBy:id,name',
            ])
            ->get()
            ->map(function (ClientMedicationAdministration $administration): ?array {
                $medication = $administration->medication;
                $rawScheduledFor = $administration->getRawOriginal('scheduled_for');
                if (! $medication instanceof ClientMedication || ! $rawScheduledFor) {
                    return null;
                }

                $scheduled = Carbon::parse((string) $rawScheduledFor, 'UTC')
                    ->timezone($this->scheduleService->workerTimezone());

                return $this->formatItem($medication, $scheduled, $administration);
            })
            ->filter()
            ->values();

        return $items
            ->sortBy([
                ['scheduled_for', 'asc'],
                ['client_name', 'asc'],
                ['medication_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /** A record's dose: its order and scheduled minute (UTC). */
    private function recordKey(ClientMedicationAdministration $administration): string
    {
        $rawScheduledFor = $administration->getRawOriginal('scheduled_for');

        return $administration->client_medication_id.':'.($rawScheduledFor
            ? Carbon::parse((string) $rawScheduledFor, 'UTC')->format('Y-m-d H:i')
            : '');
    }

    /**
     * @param  string|null  $doseState  the dose's list status (ScheduledDoseStates::listStatus) when it has no record
     * @param  string|null  $awayReason  why the person is away, when the dose is away (C7)
     * @param  string|null  $awaySource  the away record's kind (DoseAwaySources::RESPITE or LEAVE)
     * @return array<string, mixed>
     */
    private function formatItem(
        ClientMedication $medication,
        Carbon $scheduled,
        ?ClientMedicationAdministration $administration,
        ?string $doseState = null,
        ?string $awayReason = null,
        ?string $awaySource = null,
    ): array {
        $client = $medication->client;
        $clientName = $client
            ? trim(($client->first_name ?? '').' '.($client->last_name ?? ''))
            : 'Unknown client';

        return [
            'client_id' => $medication->client_id,
            'client_name' => $clientName !== '' ? $clientName : 'Unknown client',
            'client_photo_url' => $client?->profile_photo_url ?? null,
            'medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'dose' => $medication->dosage,
            'route' => $medication->route,
            'form' => $medication->form,
            'instructions' => $medication->instructions,
            'site_id' => $client?->site_id,
            'site_name' => $client?->site?->name,
            'is_controlled' => (bool) $medication->controlled_drug,
            'is_high_risk' => (bool) $medication->high_risk,
            'requires_witness' => (bool) ($medication->witness_required || $medication->controlled_drug),
            // TODO(G4): promote the existing name-derived safety prompts to
            // explicit medication fields with matching server enforcement.
            'requires_blood_glucose' => $this->requiresBloodGlucose($medication),
            'requires_pulse' => $this->requiresPulse($medication),
            'scheduled_for' => $scheduled->toIso8601String(),
            // given/refused/withheld/missed once recorded; else due, upcoming,
            // overdue, pending_check (Waiting for the order check) or away.
            'dose_state' => $administration?->status ?? $doseState ?? 'due',
            'away_reason' => $administration === null ? $awayReason : null,
            // respite or leave: what staff can do if the person is back.
            'away_source' => $administration === null ? $awaySource : null,
            'administration' => $administration ? [
                'id' => $administration->id,
                'status' => $administration->status,
                'reason' => $administration->reason,
                'reason_code' => $administration->reason_code,
                'administered_at' => $administration->administered_at?->toIso8601String(),
                'administered_by' => $administration->administeredBy?->name,
                'witnessed_by' => $administration->witnessedBy?->name,
                'blood_glucose_level' => $administration->blood_glucose_level !== null
                    ? (float) $administration->blood_glucose_level
                    : null,
                'pulse_bpm' => $administration->pulse_bpm,
            ] : null,
        ];
    }
}
