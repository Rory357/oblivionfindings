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

/**
 * Seeds /my-day lifecycle scenarios on top of SystemShiftsSeeder so a fresh
 * `migrate:fresh --seed` lets a reviewer log in as sw1@demo.test and see:
 *
 *   - A returned timesheet with manager notes (exercises the F-1 inline
 *     edit + atomic resubmit flow).
 *   - A pre-shift briefing card (a shift starting in ~30 min).
 *   - A completed shift summary in roster's "recently completed".
 */
class FrontlineLifecycleDemoSeeder extends Seeder
{
    public function run(): void
    {
        $worker = User::query()->where('email', 'sw1@demo.test')->first();
        $admin = User::query()->where('role', 'admin')->first();
        $serviceContext = ServiceContext::query()->first();

        if (! $worker || ! $admin || ! $serviceContext) {
            return;
        }

        $client = Client::query()->whereHas('supportWorkers', fn ($q) => $q->where('users.id', $worker->id))->first()
            ?? Client::query()->first();

        if (! $client) {
            return;
        }

        $this->seedReturnedTimesheet($worker, $admin, $client, $serviceContext);
        $this->seedPreShiftBriefing($worker, $admin, $client, $serviceContext);
    }

    private function seedReturnedTimesheet(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        // Pick the day before yesterday so it doesn't collide with the
        // existing yesterday-completed shift seeded by SystemShiftsSeeder.
        $workDate = Carbon::now()->subDays(2)->startOfDay();
        $starts = $workDate->copy()->setTime(7, 0);
        $ends = $workDate->copy()->setTime(15, 0);

        $shift = Shift::firstOrCreate(
            [
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'starts_at' => $starts,
            ],
            [
                'service_context_id' => $serviceContext->id,
                'ends_at' => $ends,
                'actual_starts_at' => $starts->copy()->addMinutes(2),
                'actual_ends_at' => $ends->copy()->subMinutes(3),
                'started_by' => $worker->id,
                'completed_by' => $worker->id,
                'location' => $client->city ?: 'Auckland',
                'status' => 'completed',
                'created_by' => $admin->id,
            ]
        );

        // Tasks all completed so the worker only has the timesheet to fix.
        if ($shift->tasks()->count() === 0) {
            foreach (['Handover check', 'Personal care', 'Medication round', 'Community activity', 'Environment safety check'] as $i => $label) {
                ShiftTask::create([
                    'shift_id' => $shift->id,
                    'label' => $label,
                    'sort_order' => $i,
                    'is_completed' => true,
                    'completed_at' => $ends->copy()->subMinutes(15 + $i * 3),
                    'completed_by' => $worker->id,
                ]);
            }
        }

        // Attendance session matching the shift so reconciliation does not
        // reject the resubmit later.
        HrAttendanceSession::firstOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'clock_in_at' => $shift->actual_starts_at,
                'clock_out_at' => $shift->actual_ends_at,
                'break_minutes' => 30,
                'status' => 'closed',
                'source' => 'seeder',
                'created_by' => $worker->id,
            ]
        );

        $timesheet = Timesheet::firstOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'client_id' => $client->id,
                'shift_site_id' => $shift->site_id,
                'shift_service_context_id' => $shift->service_context_id,
                'work_date' => $workDate->toDateString(),
                'starts_at' => $shift->actual_starts_at,
                'ends_at' => $shift->actual_ends_at,
                'break_minutes' => 30,
                'mileage_km' => null,
                'notes' => 'Quiet shift, all care plan tasks completed.',
                'status' => 'draft',
                'created_by' => $worker->id,
                'shift_site_name_snapshot' => $shift->site?->name ?? 'Demo Site',
                'service_context_name_snapshot' => $serviceContext->name,
                'client_name_snapshot' => trim($client->first_name.' '.$client->last_name),
                'staff_name_snapshot' => $worker->name,
                'shift_type_snapshot' => 'standard',
                'coverage_roles_snapshot' => [],
            ]
        );

        // Force the timesheet into "returned" so /my-day surfaces the inline
        // edit sheet immediately.
        $timesheet->update([
            'status' => 'returned',
            'submitted_at' => $shift->actual_ends_at?->copy()->addMinutes(5),
            'submitted_by' => $worker->id,
            'returned_at' => Carbon::now()->subHours(6),
            'returned_by' => $admin->id,
            'returned_notes' => "Please double-check the break minutes — payroll rules require at least 30 min for an 8-hour shift, and add the kilometres if you used your own vehicle.",
        ]);
    }

    private function seedPreShiftBriefing(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        // Schedule a shift to start 30 minutes from now so the pre-shift
        // briefing card appears on /my-day.
        $startsAt = Carbon::now()->addMinutes(30)->startOfMinute();
        $endsAt = $startsAt->copy()->addHours(8);

        $existing = Shift::query()
            ->where('user_id', $worker->id)
            ->whereBetween('starts_at', [$startsAt->copy()->subMinutes(5), $startsAt->copy()->addMinutes(5)])
            ->exists();

        if ($existing) {
            return;
        }

        $shift = Shift::create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => $client->city ?: 'Auckland',
            'status' => 'scheduled',
            'notes' => "Routine support shift. Family will visit between 14:00 and 15:00 — please greet them and update the visit log.",
            'created_by' => $admin->id,
        ]);

        foreach (['Welcome and orientation', 'Mid-shift check-in', 'Medication round (if due)', 'Evening meal prep', 'Handover note'] as $i => $label) {
            ShiftTask::create([
                'shift_id' => $shift->id,
                'label' => $label,
                'sort_order' => $i,
                'is_completed' => false,
            ]);
        }
    }
}
