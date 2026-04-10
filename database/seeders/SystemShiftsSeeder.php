<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SystemShiftsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('role', 'admin')->first();
        $serviceContext = ServiceContext::query()->first();

        if (!$admin || !$serviceContext) {
            return;
        }

        $clients = Client::query()->with('supportWorkers')->get();
        if ($clients->isEmpty()) {
            return;
        }

        // Create a realistic set of shifts around "today".
        // - 2 shifts today per worker
        // - 1 completed shift yesterday (for reporting)
        $today = now()->startOfDay();
        $yesterday = (clone $today)->subDay();

        $taskTemplates = [
            'Handover check',
            'Personal care',
            'Meals and hydration',
            'Community activity / engagement',
            'Medication round (if due)',
            'Environment safety check',
        ];

        foreach ($clients as $client) {
            $workers = $client->supportWorkers;
            if ($workers->isEmpty()) {
                continue;
            }

            // Pick one "primary" worker for scheduled shifts.
            $worker = $workers->first();

            // Morning shift today
            $starts = (clone $today)->setTime(7, 0);
            $ends = (clone $today)->setTime(15, 0);
            $shift = Shift::create([
                'client_id' => $client->id,
                'service_context_id' => $serviceContext->id,
                'user_id' => $worker->id,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'location' => $client->city,
                'status' => 'scheduled',
                'created_by' => $admin->id,
            ]);

            $this->seedTasks($shift, $taskTemplates, $worker, false);

            // Afternoon shift today (some in-progress)
            $starts2 = (clone $today)->setTime(15, 0);
            $ends2 = (clone $today)->setTime(23, 0);
            $status2 = now()->greaterThan($starts2) ? 'in_progress' : 'scheduled';
            $shift2 = Shift::create([
                'client_id' => $client->id,
                'service_context_id' => $serviceContext->id,
                'user_id' => $workers->count() > 1 ? $workers[1]->id : $worker->id,
                'starts_at' => $starts2,
                'ends_at' => $ends2,
                'location' => $client->city,
                'status' => $status2,
                'created_by' => $admin->id,
            ]);
            $this->seedTasks($shift2, $taskTemplates, $shift2->staff, $status2 === 'in_progress');

            // Yesterday completed shift (for reports / locking)
            $yStarts = (clone $yesterday)->setTime(7, 0);
            $yEnds = (clone $yesterday)->setTime(15, 0);
            $shiftY = Shift::create([
                'client_id' => $client->id,
                'service_context_id' => $serviceContext->id,
                'user_id' => $worker->id,
                'starts_at' => $yStarts,
                'ends_at' => $yEnds,
                'actual_starts_at' => (clone $yStarts)->addMinutes(3),
                'actual_ends_at' => (clone $yEnds)->subMinutes(2),
                'started_by' => $worker->id,
                'completed_by' => $worker->id,
                'location' => $client->city,
                'status' => 'completed',
                'created_by' => $admin->id,
            ]);
            $this->seedTasks($shiftY, $taskTemplates, $worker, true, true);

            // Attendance evidence for the completed shift (required by reconciliation).
            // A worker may be assigned to multiple clients, so skip if they already
            // have an overlapping attendance session (safety invariant rejects overlaps).
            $hasExistingSession = HrAttendanceSession::query()
                ->where('user_id', $worker->id)
                ->whereNotNull('clock_in_at')
                ->where('clock_in_at', '<', $shiftY->actual_ends_at ?? $shiftY->ends_at)
                ->where(fn ($q) => $q->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $shiftY->actual_starts_at ?? $shiftY->starts_at))
                ->exists();

            if (! $hasExistingSession) {
                HrAttendanceSession::firstOrCreate([
                    'shift_id' => $shiftY->id,
                    'user_id' => $worker->id,
                ], [
                    'clock_in_at' => $shiftY->actual_starts_at ?? $shiftY->starts_at,
                    'clock_out_at' => $shiftY->actual_ends_at ?? $shiftY->ends_at,
                    'break_minutes' => 0,
                    'status' => 'closed',
                    'source' => 'seeder',
                    'created_by' => $worker->id,
                ]);
            }

            // Timesheet for the completed shift — create as draft, then promote
            // to submitted only if attendance evidence exists (reconciliation requires it).
            $timesheet = Timesheet::firstOrCreate([
                'shift_id' => $shiftY->id,
                'user_id' => $worker->id,
            ], [
                'client_id' => $client->id,
                'work_date' => $yesterday->toDateString(),
                'starts_at' => $shiftY->actual_starts_at ?? $shiftY->starts_at,
                'ends_at' => $shiftY->actual_ends_at ?? $shiftY->ends_at,
                'status' => 'draft',
                'created_by' => $worker->id,
            ]);

            if (! $hasExistingSession) {
                // We created attendance above — safe to submit
                $timesheet->update(['status' => 'submitted']);
            }
        }
    }

    private function seedTasks(Shift $shift, array $labels, User $actor, bool $someCompleted = false, bool $allCompleted = false): void
    {
        foreach (array_values($labels) as $i => $label) {
            $completed = $allCompleted || ($someCompleted && $i < 2);

            ShiftTask::create([
                'shift_id' => $shift->id,
                'label' => $label,
                'sort_order' => $i,
                'is_completed' => $completed,
                'completed_at' => $completed ? now()->subHours(rand(1, 10)) : null,
                'completed_by' => $completed ? $actor->id : null,
            ]);
        }
    }
}
