<?php

namespace App\Services;

use App\Models\Client;
use Carbon\Carbon;

class MedicationMarService
{
    /**
     * Build the Daily MAR view for a client for a given date.
     *
     * Output is shaped for Inertia.
     */
    public function build(Client $client, Carbon $date, ?Carbon $now = null): array
    {
        $now = $now ?: now();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $client->loadMissing(['medications', 'medicationAdministrations']);

        $administrations = $client->medicationAdministrations()
            ->whereBetween('scheduled_for', [$start, $end])
            ->orWhere(function ($q) use ($start, $end) {
                // PRN / unscheduled administrations still appear in history for the day
                $q->whereNull('scheduled_for')->whereBetween('administered_at', [$start, $end]);
            })
            ->with(['medication:id,client_id,name,controlled_drug,is_prn,route,form', 'administeredBy:id,name,email'])
            ->orderBy('scheduled_for')
            ->orderBy('administered_at')
            ->get();

        $byKey = [];
        foreach ($administrations as $a) {
            $key = $a->client_medication_id . '|' . ($a->scheduled_for ? $a->scheduled_for->format('H:i') : 'prn|' . $a->id);
            // Prefer the latest record for that slot (including corrections)
            $byKey[$key] = $a;
        }

        $rows = [];
        foreach ($client->medications as $m) {
            // Step 9 state handling
            $state = $m->state ?: ($m->active ? 'active' : 'ceased');
            if ($state !== 'active') {
                // Still show PRN even when paused? We'll hide when ceased.
                if ($state === 'ceased') {
                    continue;
                }
            }

            // date range
            if ($m->start_date && $date->lt($m->start_date)) {
                continue;
            }
            if ($m->end_date && $date->gt($m->end_date)) {
                continue;
            }
            if ($m->ceased_at && $date->gt($m->ceased_at)) {
                continue;
            }

            $doseTimes = [];
            if (is_array($m->dose_times)) {
                $doseTimes = $m->dose_times;
            } elseif (is_string($m->dose_times) && $m->dose_times !== '') {
                $decoded = json_decode($m->dose_times, true);
                if (is_array($decoded)) {
                    $doseTimes = $decoded;
                }
            }

            if (empty($doseTimes) && !$m->is_prn) {
                // fallback single schedule at 09:00
                $doseTimes = ['09:00'];
            }

            // Build scheduled rows
            foreach ($doseTimes as $t) {
                $scheduled = $date->copy()->setTimeFromTimeString($t);

                $slotKey = $m->id . '|' . $scheduled->format('H:i');
                $existing = $byKey[$slotKey] ?? null;

                $status = $existing?->status ?: null;
                $scheduleState = $this->scheduleState($scheduled, $now, $date->isToday());

                $rows[] = [
                    'medication' => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'dosage' => $m->dosage,
                        'route' => $m->route,
                        'form' => $m->form,
                        'is_prn' => (bool) $m->is_prn,
                        'prn_reason' => $m->prn_reason,
                        'controlled_drug' => (bool) $m->controlled_drug,
                        'state' => $state,
                    ],
                    'scheduled_for' => $scheduled->toIso8601String(),
                    'scheduled_time' => $scheduled->format('H:i'),
                    'schedule_state' => $scheduleState,
                    'record' => $existing ? [
                        'id' => $existing->id,
                        'created_at' => optional($existing->created_at)->toIso8601String(),
                        'status' => $existing->status,
                        'reason' => $existing->reason,
                        'notes' => $existing->notes,
                        'dose_given' => $existing->dose_given,
                        'administered_at' => optional($existing->administered_at)->toIso8601String(),
                        'administered_by' => $existing->administeredBy ? [
                            'id' => $existing->administeredBy->id,
                            'name' => $existing->administeredBy->name,
                        ] : null,
                        'witnessed_by' => null,
                        'is_correction' => (bool) $existing->is_correction,
                        'corrected_of_id' => $existing->corrected_of_id,
                        'correction_reason' => $existing->correction_reason,
                    ] : null,
                ];
            }

            // PRN meds show a PRN card even without schedules
            if ($m->is_prn) {
                $rows[] = [
                    'medication' => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'dosage' => $m->dosage,
                        'route' => $m->route,
                        'form' => $m->form,
                        'is_prn' => true,
                        'prn_reason' => $m->prn_reason,
                        'controlled_drug' => (bool) $m->controlled_drug,
                        'state' => $state,
                    ],
                    'scheduled_for' => null,
                    'scheduled_time' => 'PRN',
                    'schedule_state' => 'prn',
                    'record' => null,
                ];
            }
        }

        usort($rows, function ($a, $b) {
            // PRN at bottom
            if ($a['scheduled_for'] === null && $b['scheduled_for'] !== null) return 1;
            if ($a['scheduled_for'] !== null && $b['scheduled_for'] === null) return -1;
            return strcmp((string) $a['scheduled_time'], (string) $b['scheduled_time']);
        });

        // Step 9 + Step 15: history (include unscheduled + corrections)
        $history = $client->medicationAdministrations()
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('administered_at', [$start, $end])
                    ->orWhereBetween('scheduled_for', [$start, $end]);
            })
            ->with(['medication:id,client_id,name', 'administeredBy:id,name'])
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'created_at' => optional($a->created_at)->toIso8601String(),
                'medication' => $a->medication,
                'status' => $a->status,
                'reason' => $a->reason,
                'notes' => $a->notes,
                'scheduled_for' => optional($a->scheduled_for)->toIso8601String(),
                'administered_at' => optional($a->administered_at)->toIso8601String(),
                'administeredBy' => $a->administeredBy,
                'is_correction' => (bool) $a->is_correction,
                'corrected_of_id' => $a->corrected_of_id,
                'correction_reason' => $a->correction_reason,
            ])
            ->values();

        return [
            'rows' => $rows,
            'history' => $history,
        ];
    }

    public function scheduleState(Carbon $scheduledFor, Carbon $now, bool $isToday): string
    {
        if (!$isToday) {
            return 'historical';
        }

        $dueSoonMinutes = 60;
        $lateAfterMinutes = 30;
        $missAfterMinutes = 180;

        $diff = $scheduledFor->diffInMinutes($now, false); // now - scheduled

        if ($diff < -$dueSoonMinutes) {
            return 'upcoming';
        }
        if ($diff >= -$dueSoonMinutes && $diff < 0) {
            return 'due_soon';
        }
        if ($diff >= 0 && $diff <= $lateAfterMinutes) {
            return 'due';
        }
        if ($diff > $lateAfterMinutes && $diff <= $missAfterMinutes) {
            return 'late';
        }
        return 'missed_auto';
    }
}
