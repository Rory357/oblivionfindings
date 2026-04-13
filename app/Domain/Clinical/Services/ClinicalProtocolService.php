<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ClinicalProtocolService
{
    /**
     * Generate schedule items for a protocol over a date range.
     *
     * Idempotent — skips items that already exist for the same protocol+due_at.
     *
     * @return Collection<int, ClinicalProtocolSchedule>
     */
    public function generateSchedule(
        ClinicalProtocol $protocol,
        Carbon $from,
        Carbon $to,
    ): Collection {
        if (! $protocol->isCurrentlyApplicable()) {
            return collect();
        }

        // EveryShift protocols don't generate time-based schedules.
        // They generate tasks per-shift via generateShiftTasks().
        if ($protocol->frequency === ProtocolFrequency::EveryShift) {
            return collect();
        }

        $intervalHours = $protocol->effectiveIntervalHours();
        if (! $intervalHours || $intervalHours <= 0) {
            return collect();
        }

        $created = collect();
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $existing = ClinicalProtocolSchedule::query()
                ->where('clinical_protocol_id', $protocol->id)
                ->where('due_at', $cursor->copy())
                ->exists();

            if (! $existing) {
                $created->push(ClinicalProtocolSchedule::create([
                    'clinical_protocol_id' => $protocol->id,
                    'due_at' => $cursor->copy(),
                    'status' => 'pending',
                ]));
            }

            $cursor->addHours($intervalHours);
        }

        return $created;
    }

    /**
     * Get observations due for a client (pending schedule items, including overdue).
     *
     * @return Collection<int, ClinicalProtocolSchedule>
     */
    public function getDueForClient(Client $client): Collection
    {
        return ClinicalProtocolSchedule::query()
            ->pending()
            ->whereHas('protocol', function ($q) use ($client) {
                $q->where('client_id', $client->id)->active();
            })
            ->with('protocol')
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Get observations due for a specific shift.
     *
     * Includes:
     * - EveryShift protocols for the shift's client (always due)
     * - Time-based protocols with pending items due within the shift window
     *
     * @return Collection<int, array{protocol: ClinicalProtocol, schedule: ?ClinicalProtocolSchedule}>
     */
    public function getDueForShift(Shift $shift): Collection
    {
        if (! $shift->client_id) {
            return collect();
        }

        $protocols = ClinicalProtocol::query()
            ->forClient($shift->client_id)
            ->active()
            ->get();

        $due = collect();

        foreach ($protocols as $protocol) {
            if (! $protocol->isCurrentlyApplicable()) {
                continue;
            }

            if ($protocol->frequency === ProtocolFrequency::EveryShift) {
                // Check if already completed for this shift
                $alreadyDone = ClinicalObservation::query()
                    ->forClient($shift->client_id)
                    ->forShift($shift->id)
                    ->ofType($protocol->observation_type)
                    ->exists();

                if (! $alreadyDone) {
                    $due->push([
                        'protocol' => $protocol,
                        'schedule' => null, // EveryShift items don't use pre-generated schedules
                    ]);
                }

                continue;
            }

            // Time-based: find pending schedule items within shift window
            if ($shift->starts_at && $shift->ends_at) {
                $pendingItems = ClinicalProtocolSchedule::query()
                    ->where('clinical_protocol_id', $protocol->id)
                    ->pending()
                    ->whereBetween('due_at', [$shift->starts_at, $shift->ends_at])
                    ->get();

                foreach ($pendingItems as $item) {
                    $due->push([
                        'protocol' => $protocol,
                        'schedule' => $item,
                    ]);
                }
            }
        }

        return $due;
    }

    /**
     * Create ShiftTask records for protocol items due on a shift.
     *
     * Follows the FamilyNoteController dedup pattern:
     * - Check label existence before creating
     * - Use max(sort_order) + 1 for ordering
     *
     * @return Collection<int, ShiftTask>
     */
    public function generateShiftTasks(Shift $shift): Collection
    {
        $dueItems = $this->getDueForShift($shift);
        $created = collect();

        foreach ($dueItems as $item) {
            /** @var ClinicalProtocol $protocol */
            $protocol = $item['protocol'];
            /** @var ?ClinicalProtocolSchedule $schedule */
            $schedule = $item['schedule'];

            // Skip if schedule already has a linked task
            if ($schedule && $schedule->shift_task_id) {
                continue;
            }

            $label = $this->buildTaskLabel($protocol);

            // Dedup: don't create if identical task already exists on this shift
            if (ShiftTask::where('shift_id', $shift->id)->where('label', $label)->exists()) {
                continue;
            }

            $sortOrder = ((int) ShiftTask::where('shift_id', $shift->id)->max('sort_order')) + 1;

            $task = ShiftTask::create([
                'shift_id' => $shift->id,
                'label' => $label,
                'is_completed' => false,
                'sort_order' => $sortOrder,
            ]);

            // Link schedule item to the created task
            if ($schedule) {
                $schedule->updateQuietly(['shift_task_id' => $task->id]);
            }

            $created->push($task);
        }

        return $created;
    }

    /**
     * Get overdue schedule items for a client.
     *
     * @return Collection<int, ClinicalProtocolSchedule>
     */
    public function getOverdue(Client $client): Collection
    {
        return ClinicalProtocolSchedule::query()
            ->overdue()
            ->whereHas('protocol', function ($q) use ($client) {
                $q->where('client_id', $client->id)->active();
            })
            ->with('protocol')
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Calculate compliance rate for a client's protocols.
     *
     * Returns percentage of schedule items completed vs total (completed + missed + pending-overdue).
     */
    public function getComplianceRate(Client $client, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        $query = ClinicalProtocolSchedule::query()
            ->whereHas('protocol', function ($q) use ($client) {
                $q->where('client_id', $client->id);
            })
            ->whereBetween('due_at', [$from, $to]);

        $total = (clone $query)->count();

        if ($total === 0) {
            return 100.0;
        }

        $completed = (clone $query)->where('status', 'completed')->count();

        return round(($completed / $total) * 100, 1);
    }

    /**
     * Build task label for a protocol-generated shift task.
     */
    protected function buildTaskLabel(ClinicalProtocol $protocol): string
    {
        return '📋 ' . $protocol->observation_type->label() . ': ' . $protocol->name;
    }
}
