<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ServiceContext;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EnhancedMarService
{
    protected MarScheduleService $scheduleService;
    protected MedicationSafetyService $safetyService;
    protected MedicationScanVerificationService $scanVerificationService;

    public function __construct(
        MarScheduleService $scheduleService,
        MedicationSafetyService $safetyService,
        MedicationScanVerificationService $scanVerificationService
    ) {
        $this->scheduleService = $scheduleService;
        $this->safetyService = $safetyService;
        $this->scanVerificationService = $scanVerificationService;
    }

    /**
     * Build the enhanced MAR view for a client
     */
    public function build(Client $client, Carbon $date, ?Carbon $now = null, ?int $activeShiftId = null): array
    {
        $now = $now ?? now();
        $isToday = $date->isToday();
        
        // Get client's allergies for safety display
        $allergies = $client->medicationAllergies()
            ->whereNull('deleted_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'allergen' => $a->allergen,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
                'is_severe' => $a->isSevere(),
            ])
            ->toArray();

        // Get active medications
        $medications = $client->medications()
            ->active()
            ->where(function ($q) use ($date) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->with(['stock'])
            ->get();

        // Build scheduled rows
        $scheduledRows = [];
        $prnRows = [];

        foreach ($medications as $medication) {
            // Build scheduled doses for non-PRN medications
            if (!$medication->is_prn) {
                $scheduledTimes = $this->scheduleService->scheduledTimesForDate($medication, $date);
                
                foreach ($scheduledTimes as $scheduledFor) {
                    $row = $this->buildScheduledRow($medication, $scheduledFor, $now, $date, $client, $activeShiftId);
                    $scheduledRows[] = $row;
                }
            } else {
                // Build PRN row
                $prnRows[] = $this->buildPrnRow($medication, $now, $client, $activeShiftId);
            }
        }

        // Sort scheduled rows by time
        usort($scheduledRows, fn ($a, $b) => strcmp($a['scheduled_time'], $b['scheduled_time']));

        // Get recent administration history
        $history = $this->getHistory($client, $date);

        // Get upcoming doses (for today view)
        $upcoming = $isToday ? $this->getUpcomingDoses($scheduledRows, $now) : [];

        // Calculate summary stats
        $stats = $this->calculateStats($scheduledRows, $prnRows, $history);

        return [
            'date' => $date->toDateString(),
            'is_today' => $isToday,
            'scheduled' => $scheduledRows,
            'prn' => $prnRows,
            'history' => $history,
            'upcoming' => $upcoming,
            'stats' => $stats,
            'allergies' => $allergies,
            'settings' => [
                'window_before_minutes' => $this->scheduleService->windowBeforeMinutes(),
                'window_after_minutes' => $this->scheduleService->windowAfterMinutes(),
                'due_soon_minutes' => $this->scheduleService->dueSoonMinutes(),
            ],
            'active_shift_id' => $activeShiftId,
        ];
    }

    /**
     * Build a scheduled medication row
     */
    private function buildScheduledRow(
        ClientMedication $medication,
        Carbon $scheduledFor,
        Carbon $now,
        Carbon $date,
        Client $client,
        ?int $activeShiftId
    ): array {
        // Get existing administration for this slot
        $existing = ClientMedicationAdministration::where('client_medication_id', $medication->id)
            ->whereBetween('scheduled_for', [
                $scheduledFor->copy()->subMinute(),
                $scheduledFor->copy()->addMinute(),
            ])
            ->with(['administeredBy:id,name', 'witnessedBy:id,name'])
            ->first();

        $scheduleState = $this->getScheduleState($scheduledFor, $now, $date->isToday(), $existing);
        [$windowStart, $windowEnd] = $this->scheduleService->windowForScheduled($scheduledFor);

        // Perform safety check
        $safetyCheck = $existing ? null : $this->safetyService->performSafetyCheck($client, $medication, $now);

        return [
            'id' => $existing?->id,
            'client_medication_id' => $medication->id,
            'medication' => $this->formatMedication($medication, $client),
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'scheduled_time' => $scheduledFor->format('H:i'),
            'schedule_state' => $scheduleState,
            'schedule_state_label' => $this->getScheduleStateLabel($scheduleState),
            'window_start' => $windowStart->toIso8601String(),
            'window_end' => $windowEnd->toIso8601String(),
            'can_record' => $scheduleState !== 'completed' && $scheduleState !== 'future',
            'is_overdue' => $scheduleState === 'missed_auto' || $scheduleState === 'late',
            'requires_witness' => $medication->requiresWitness(),
            'administration' => $existing ? $this->formatAdministration($existing) : null,
            'safety_check' => $safetyCheck,
            'is_correction' => $existing?->is_correction ?? false,
        ];
    }

    /**
     * Build a PRN medication row
     */
    private function buildPrnRow(
        ClientMedication $medication,
        Carbon $now,
        Client $client,
        ?int $activeShiftId
    ): array {
        // Get PRN history
        $prnHistory = $this->safetyService->getPrnHistory($medication, 24);
        
        // Perform safety check
        $safetyCheck = $this->safetyService->performSafetyCheck($client, $medication, $now);

        return [
            'client_medication_id' => $medication->id,
            'medication' => $this->formatMedication($medication, $client),
            'is_prn' => true,
            'prn_reason' => $medication->prn_reason,
            'max_per_day' => $medication->max_per_day,
            'count_24h' => $prnHistory['count'],
            'remaining_today' => $prnHistory['remaining_today'],
            'prn_history' => $prnHistory['history'],
            'is_near_limit' => $medication->isPrnNearLimit(),
            'is_over_limit' => $medication->isPrnOverLimit(),
            'is_blocked' => $safetyCheck['blocked'],
            'can_record' => !$safetyCheck['blocked'] && $medication->isActive(),
            'requires_witness' => $medication->requiresWitness(),
            'safety_check' => $safetyCheck,
        ];
    }

    /**
     * Format medication for display
     */
    private function formatMedication(ClientMedication $medication, ?Client $client = null): array
    {
        return [
            'id' => $medication->id,
            'name' => $medication->name,
            'dosage' => $medication->formatted_dose,
            'route' => $medication->route,
            'form' => $medication->form,
            'instructions' => $medication->instructions,
            'indication' => $medication->indication,
            'is_prn' => $medication->is_prn,
            'prn_reason' => $medication->prn_reason,
            'controlled_drug' => $medication->controlled_drug,
            'high_risk' => $medication->high_risk,
            'witness_required' => $medication->witness_required,
            'prescriber' => $medication->prescriber,
            'pharmacy' => $medication->pharmacy,
            'state' => $medication->state,
            'stock' => $medication->stock ? [
                'on_hand' => $medication->stock->on_hand,
                'unit' => $medication->stock->unit,
                'reorder_level' => $medication->stock->reorder_level,
            ] : null,
            'scan_verification' => $client
                ? $this->scanVerificationService->payload($client, $medication)
                : null,
        ];
    }

    /**
     * Format administration for display
     */
    private function formatAdministration(ClientMedicationAdministration $admin): array
    {
        return [
            'id' => $admin->id,
            'status' => $admin->status,
            'status_label' => $this->getStatusLabel($admin->status),
            'dose_given' => $admin->dose_given,
            'reason' => $admin->reason,
            'notes' => $admin->notes,
            'scheduled_for' => $admin->scheduled_for?->toIso8601String(),
            'administered_at' => $admin->administered_at?->toIso8601String(),
            'administered_by' => $admin->administeredBy?->name,
            'witnessed_by' => $admin->witnessedBy?->name,
            'late_minutes' => $admin->late_minutes,
            'early_minutes' => $admin->early_minutes,
            'is_correction' => $admin->is_correction,
            'correction_reason' => $admin->correction_reason,
            'outcome' => $admin->outcome,
        ];
    }

    /**
     * Get schedule state
     */
    private function getScheduleState(Carbon $scheduledFor, Carbon $now, bool $isToday, ?ClientMedicationAdministration $existing): string
    {
        if ($existing) {
            return 'completed';
        }

        if (!$isToday) {
            return $scheduledFor->isPast() ? 'missed_auto' : 'future';
        }

        $dueSoonMinutes = 60;
        $lateAfterMinutes = 30;
        $missAfterMinutes = 180;

        $diff = $scheduledFor->diffInMinutes($now, false);

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

    /**
     * Get schedule state label
     */
    private function getScheduleStateLabel(string $state): array
    {
        return match ($state) {
            'upcoming' => ['label' => 'Upcoming', 'color' => 'slate', 'icon' => 'clock'],
            'due_soon' => ['label' => 'Due Soon', 'color' => 'yellow', 'icon' => 'alert-circle'],
            'due' => ['label' => 'Due', 'color' => 'blue', 'icon' => 'check-circle'],
            'late' => ['label' => 'Late', 'color' => 'orange', 'icon' => 'alert-triangle'],
            'missed_auto' => ['label' => 'Missed', 'color' => 'red', 'icon' => 'x-circle'],
            'completed' => ['label' => 'Given', 'color' => 'green', 'icon' => 'check'],
            'future' => ['label' => 'Future', 'color' => 'slate', 'icon' => 'calendar'],
            default => ['label' => $state, 'color' => 'gray', 'icon' => 'help-circle'],
        };
    }

    /**
     * Get status label
     */
    private function getStatusLabel(string $status): array
    {
        return match ($status) {
            'given' => ['label' => 'Given', 'color' => 'green'],
            'refused' => ['label' => 'Refused', 'color' => 'orange'],
            'withheld' => ['label' => 'Withheld', 'color' => 'yellow'],
            'missed' => ['label' => 'Missed', 'color' => 'red'],
            default => ['label' => $status, 'color' => 'gray'],
        };
    }

    /**
     * Get administration history for the date
     */
    private function getHistory(Client $client, Carbon $date): array
    {
        return ClientMedicationAdministration::where('client_id', $client->id)
            ->where(function ($q) use ($date) {
                $q->whereBetween('administered_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                    ->orWhereBetween('scheduled_for', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
            })
            ->with(['medication:id,name,dosage,controlled_drug', 'administeredBy:id,name', 'witnessedBy:id,name'])
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'medication_name' => $a->medication?->name ?? 'Unknown',
                'status' => $a->status,
                'status_label' => $this->getStatusLabel($a->status),
                'dose_given' => $a->dose_given,
                'reason' => $a->reason,
                'notes' => $a->notes,
                'administered_at' => $a->administered_at?->toIso8601String(),
                'scheduled_for' => $a->scheduled_for?->toIso8601String(),
                'administered_by' => $a->administeredBy?->name,
                'witnessed_by' => $a->witnessedBy?->name,
                'is_correction' => $a->is_correction,
                'correction_reason' => $a->correction_reason,
                'controlled_drug' => $a->medication?->controlled_drug ?? false,
            ])
            ->toArray();
    }

    /**
     * Get upcoming doses for today
     */
    private function getUpcomingDoses(array $scheduledRows, Carbon $now): array
    {
        return collect($scheduledRows)
            ->filter(fn ($r) => in_array($r['schedule_state'], ['due', 'due_soon', 'late'], true))
            ->sortBy('scheduled_for')
            ->values()
            ->toArray();
    }

    /**
     * Calculate MAR statistics
     */
    private function calculateStats(array $scheduledRows, array $prnRows, array $history): array
    {
        $scheduledStats = [
            'total' => count($scheduledRows),
            'completed' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'completed')),
            'due' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'due')),
            'late' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'late')),
            'missed' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'missed_auto')),
            'upcoming' => count(array_filter($scheduledRows, fn ($r) => in_array($r['schedule_state'], ['upcoming', 'due_soon']))),
        ];

        $prnStats = [
            'total_medications' => count($prnRows),
            'near_limit' => count(array_filter($prnRows, fn ($r) => $r['is_near_limit'])),
            'over_limit' => count(array_filter($prnRows, fn ($r) => $r['is_over_limit'])),
        ];

        $controlledCount = count(array_filter($scheduledRows, fn ($r) => $r['medication']['controlled_drug']))
            + count(array_filter($prnRows, fn ($r) => $r['medication']['controlled_drug']));

        return [
            'scheduled' => $scheduledStats,
            'prn' => $prnStats,
            'controlled_count' => $controlledCount,
            'history_count' => count($history),
            'completion_percentage' => $scheduledStats['total'] > 0 
                ? round(($scheduledStats['completed'] / $scheduledStats['total']) * 100, 1)
                : 0,
        ];
    }

    /**
     * Record medication administration
     */
    public function recordAdministration(
        Client $client,
        ClientMedication $medication,
        array $data,
        int $userId,
        ?int $shiftId = null
    ): array {
        // Validate safety check (including dose validation)
        $safetyCheck = $this->safetyService->performSafetyCheck(
            $client,
            $medication,
            null,
            $data['dose_given'] ?? null
        );

        if ($safetyCheck['blocked'] && !($data['override_safety'] ?? false)) {
            return [
                'success' => false,
                'error' => $safetyCheck['block_reason'],
                'error_field' => 'client_medication_id',
                'safety_check' => $safetyCheck,
            ];
        }

        // Validate time window for scheduled doses
        $scheduledFor = isset($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : null;
        $adminAt = isset($data['administered_at']) ? Carbon::parse($data['administered_at']) : now();
        $windowCheck = null;

        if ($scheduledFor && !$medication->is_prn) {
            $windowCheck = $this->safetyService->validateTimeWindow(
                $scheduledFor,
                $adminAt,
                $this->scheduleService->windowBeforeMinutes(),
                $this->scheduleService->windowAfterMinutes()
            );

            if (!$windowCheck['valid'] && empty($data['reason']) && !($data['override_window'] ?? false)) {
                return [
                    'success' => false,
                    'error' => 'Outside time window: ' . $windowCheck['message'] . '. Please provide a reason.',
                    'error_field' => 'reason',
                    'time_window' => $windowCheck,
                ];
            }
        }

        return DB::transaction(function () use ($client, $medication, $data, $userId, $shiftId, $safetyCheck, $scheduledFor, $adminAt, $windowCheck) {
            // Re-fetch medication with lock to prevent race conditions
            $medication = ClientMedication::lockForUpdate()->findOrFail($medication->id);

            // Lock stock record if it exists
            if ($medication->controlled_drug) {
                $medication->load(['stock' => function ($q) {
                    $q->lockForUpdate();
                }]);
            }

            // Create administration record
            $admin = new ClientMedicationAdministration();
            $admin->client_id = $client->id;
            $admin->client_medication_id = $medication->id;
            $admin->shift_id = $shiftId;
            $admin->administered_by = $userId;
            $admin->witnessed_by = $data['witnessed_by'] ?? null;
            $admin->scheduled_for = $scheduledFor;
            $admin->administered_at = $adminAt;
            $admin->status = $data['status'];
            $admin->reason = $data['reason'] ?? null;
            $admin->dose_given = $data['dose_given'] ?? null;
            $admin->notes = $data['notes'] ?? null;
            $admin->late_minutes = $windowCheck['late_minutes'] ?? null;
            $admin->early_minutes = $windowCheck['early_minutes'] ?? null;
            $admin->outcome = $data['outcome'] ?? null;
            $admin->site = $data['site'] ?? null;

            if ($shiftId) {
                $shift = Shift::find($shiftId);
                $admin->service_context_id = $shift?->service_context_id;
            }

            if (! $admin->service_context_id) {
                $admin->service_context_id = $client->service_context_id ?: ServiceContext::defaultId();
            }

            $admin->save();

            // Handle controlled drug register entry
            if ($medication->controlled_drug && $admin->status === 'given') {
                $this->recordControlledDrugEntry($medication, $admin, $userId, $data['witnessed_by'] ?? null);
            }

            return [
                'success' => true,
                'administration' => $admin,
                'safety_check' => $safetyCheck,
            ];
        });
    }

    /**
     * Record controlled drug entry
     */
    private function recordControlledDrugEntry(
        ClientMedication $medication,
        ClientMedicationAdministration $admin,
        int $recordedBy,
        ?int $witnessedBy
    ): void {
        $stock = $medication->stock;
        $before = $stock?->on_hand;
        
        // Update stock if applicable
        if ($stock && $before !== null) {
            $stock->on_hand = max(0, $before - 1);
            $stock->last_counted_at = now();
            $stock->save();
        }

        // Create controlled drug register entry
        \App\Models\ClientControlledDrugEntry::create([
            'client_id' => $admin->client_id,
            'client_medication_id' => $medication->id,
            'shift_id' => $admin->shift_id,
            'service_context_id' => $admin->service_context_id,
            'entry_type' => 'administered',
            'quantity' => 1,
            'unit' => $stock?->unit,
            'on_hand_before' => $before,
            'on_hand_after' => $stock?->on_hand,
            'reason' => $admin->reason,
            'notes' => $admin->notes,
            'recorded_at' => $admin->administered_at,
            'recorded_by' => $recordedBy,
            'witnessed_by' => $witnessedBy,
        ]);
    }

    /**
     * Get shift medication summary
     */
    public function getShiftSummary(int $shiftId): array
    {
        $shift = Shift::with('client')->findOrFail($shiftId);
        $client = $shift->client;

        if (!$client) {
            return ['error' => 'No client associated with this shift'];
        }

        $administrations = ClientMedicationAdministration::where('shift_id', $shiftId)
            ->with('medication:id,name,controlled_drug,is_prn')
            ->orderByDesc('administered_at')
            ->get();

        $scheduledCount = $administrations->where('medication.is_prn', false)->count();
        $prnCount = $administrations->where('medication.is_prn', true)->count();
        $controlledCount = $administrations->where('medication.controlled_drug', true)->count();

        $byStatus = $administrations->groupBy('status')
            ->map(fn ($group) => $group->count())
            ->toArray();

        return [
            'shift_id' => $shiftId,
            'client_name' => $client->full_name,
            'date' => $shift->starts_at?->toDateString(),
            'total_administrations' => $administrations->count(),
            'scheduled_count' => $scheduledCount,
            'prn_count' => $prnCount,
            'controlled_count' => $controlledCount,
            'by_status' => $byStatus,
            'administrations' => $administrations->map(fn ($a) => [
                'id' => $a->id,
                'medication_name' => $a->medication?->name ?? 'Unknown',
                'status' => $a->status,
                'administered_at' => $a->administered_at?->toIso8601String(),
                'is_controlled' => $a->medication?->controlled_drug ?? false,
                'is_prn' => $a->medication?->is_prn ?? false,
            ])->toArray(),
        ];
    }
}
