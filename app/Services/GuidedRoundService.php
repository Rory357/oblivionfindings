<?php

namespace App\Services;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
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
    public function __construct(protected MarScheduleService $scheduleService) {}

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
     *     administration: array|null,
     * }>
     */
    public function items(MedicationRound $round): array
    {
        $date = $this->scheduleService->dateFromInput(
            $round->round_date instanceof Carbon
                ? $round->round_date->toDateString()
                : (string) $round->round_date,
        );

        $windowMinutes = max(0, (int) ($round->window_minutes ?? 60));
        $roundTime = $date->copy()->setTimeFromTimeString($round->scheduled_time);
        $windowStart = $roundTime->copy()->subMinutes($windowMinutes);
        $windowEnd = $roundTime->copy()->addMinutes($windowMinutes);

        $medications = ClientMedication::query()
            ->active()
            ->where(function ($q) {
                $q->where('is_prn', false)->orWhereNull('is_prn');
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->whereHas('client', function ($q) use ($round) {
                if ($round->site_id) {
                    $q->where('site_id', $round->site_id);
                }
                if ($round->service_context_id) {
                    $q->where('service_context_id', $round->service_context_id);
                }
            })
            ->with(['client:id,first_name,last_name,profile_photo_path,site_id', 'client.site:id,name'])
            ->get();

        $administrations = ClientMedicationAdministration::query()
            ->where('medication_round_id', $round->id)
            ->with(['administeredBy:id,name', 'witnessedBy:id,name'])
            ->get()
            ->keyBy(function (ClientMedicationAdministration $administration) {
                $rawScheduledFor = $administration->getRawOriginal('scheduled_for');
                $scheduledKey = $rawScheduledFor
                    ? Carbon::parse((string) $rawScheduledFor, 'UTC')->format('Y-m-d H:i')
                    : '';

                return $administration->client_medication_id.':'.$scheduledKey;
            });

        $items = new Collection;

        foreach ($medications as $med) {
            foreach ($this->scheduleService->scheduledTimesForDate($med, $date) as $scheduled) {
                if (! $scheduled->between($windowStart, $windowEnd, true)) {
                    continue;
                }

                $key = $med->id.':'.$scheduled->copy()->utc()->format('Y-m-d H:i');
                $admin = $administrations->get($key);

                $client = $med->client;
                $clientName = $client
                    ? trim(($client->first_name ?? '').' '.($client->last_name ?? ''))
                    : 'Unknown client';

                $items->push([
                    'client_id' => $med->client_id,
                    'client_name' => $clientName !== '' ? $clientName : 'Unknown client',
                    'client_photo_url' => $client?->profile_photo_url ?? null,
                    'medication_id' => $med->id,
                    'medication_name' => $med->name,
                    'dose' => $med->dosage,
                    'route' => $med->route,
                    'form' => $med->form,
                    'instructions' => $med->instructions,
                    'site_id' => $client?->site_id,
                    'site_name' => $client?->site?->name,
                    'is_controlled' => (bool) $med->controlled_drug,
                    'is_high_risk' => (bool) $med->high_risk,
                    'requires_witness' => (bool) ($med->witness_required || $med->controlled_drug),
                    // TODO(G4): no dedicated requires_blood_glucose / requires_pulse columns on
                    // client_medications yet — derived from the medication name/route here so the
                    // guided modal can flag-gate vitals (witness/BG/pulse) without a regex in the
                    // frontend. Promote to real columns + server-side enforcement per gap G4.
                    'requires_blood_glucose' => $this->requiresBloodGlucose($med),
                    'requires_pulse' => $this->requiresPulse($med),
                    'scheduled_for' => $scheduled->toIso8601String(),
                    'administration' => $admin ? [
                        'id' => $admin->id,
                        'status' => $admin->status,
                        'reason' => $admin->reason,
                        'reason_code' => $admin->reason_code,
                        'administered_at' => $admin->administered_at?->toIso8601String(),
                        'administered_by' => $admin->administeredBy?->name,
                        'witnessed_by' => $admin->witnessedBy?->name,
                        'blood_glucose_level' => $admin->blood_glucose_level !== null ? (float) $admin->blood_glucose_level : null,
                        'pulse_bpm' => $admin->pulse_bpm,
                    ] : null,
                ]);
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
    public function progress(MedicationRound $round): array
    {
        return $this->summarise($this->items($round));
    }

    /**
     * @param  array<int, array>  $items
     */
    public function summarise(array $items): array
    {
        $total = count($items);
        $given = 0;
        $refused = 0;
        $held = 0;
        $pending = 0;
        $nextIndex = null;

        foreach ($items as $idx => $item) {
            $status = $item['administration']['status'] ?? null;
            if ($status === 'given') {
                $given++;
            } elseif ($status === 'refused') {
                $refused++;
            } elseif ($status === 'withheld' || $status === 'missed') {
                $held++;
            } else {
                $pending++;
                if ($nextIndex === null) {
                    $nextIndex = $idx;
                }
            }
        }

        $completed = $total - $pending;

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'given' => $given,
            'refused' => $refused,
            'held' => $held,
            'next_index' => $nextIndex,
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
        ];
    }

    /**
     * Flatten the round's doses into "cells" for the Resident × Round chart and
     * the per-round audit timeline. Reuses items() (one schedule pipeline) and
     * hoists the administration outcome onto each cell — status defaults to
     * "due" for an un-actioned dose.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cells(MedicationRound $round): array
    {
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
                'status' => $admin['status'] ?? 'due',
                'witnessed_by' => $admin['witnessed_by'] ?? null,
                'blood_glucose_level' => $admin['blood_glucose_level'] ?? null,
                'pulse_bpm' => $admin['pulse_bpm'] ?? null,
                'reason' => $admin['reason'] ?? null,
                'reason_code' => $admin['reason_code'] ?? null,
                'administered_at' => $admin['administered_at'] ?? null,
                'administered_by' => $admin['administered_by'] ?? null,
            ];
        }, $this->items($round));
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
}
