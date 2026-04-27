<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
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

        // Site access: TimesheetController::assertCanAccessTimesheet checks
        // hrEmployeeProfile.primary_site_id (or secondary_site_ids / user.site_id)
        // against the timesheet's site. Without a matching site assignment the
        // worker hits 403 on update/submit/resubmit. Grant sw1 access to the
        // demo client's site so the lifecycle flow is exercisable end-to-end.
        $this->grantSiteAccess($worker, $client);

        $this->seedReturnedTimesheet($worker, $admin, $client, $serviceContext);
        $this->seedPreShiftBriefing($worker, $admin, $client, $serviceContext);
    }

    private function grantSiteAccess(User $worker, Client $client): void
    {
        if (! $client->site_id) {
            return;
        }

        $profile = HrEmployeeProfile::query()->where('user_id', $worker->id)->first();
        if (! $profile) {
            // SystemUsersSeeder is expected to create one already, but be
            // defensive in case the seed order changes.
            $profile = HrEmployeeProfile::create([
                'user_id' => $worker->id,
                'primary_site_id' => $client->site_id,
            ]);

            return;
        }

        $secondary = is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [];
        $needsPrimary = ! filled($profile->primary_site_id);
        $alreadyHas = $profile->primary_site_id === $client->site_id
            || in_array($client->site_id, $secondary, true);

        if ($alreadyHas) {
            return;
        }

        if ($needsPrimary) {
            $profile->update(['primary_site_id' => $client->site_id]);

            return;
        }

        $profile->update([
            'secondary_site_ids' => array_values(array_unique([...$secondary, $client->site_id])),
        ]);
    }

    private function seedReturnedTimesheet(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        // Pick the day before yesterday so it doesn't collide with the
        // existing yesterday-completed shift seeded by SystemShiftsSeeder.
        $workDate = Carbon::now()->subDays(2)->startOfDay();
        $starts = $workDate->copy()->setTime(7, 0);
        $ends = $workDate->copy()->setTime(15, 0);

        // Align actual times exactly to planned and pre-declare the shift's
        // expected break so reconciliation passes:
        //   planned_minutes  = (ends - starts) - expected_break_minutes
        //   attendance       = (clock_out - clock_in) - break_minutes
        // Both end up at 450 (8h shift with a 30 min break), and the
        // timesheet itself uses break_minutes=30 too, so all three match.
        $shift = Shift::firstOrCreate(
            [
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'starts_at' => $starts,
            ],
            [
                'service_context_id' => $serviceContext->id,
                'ends_at' => $ends,
                'actual_starts_at' => $starts,
                'actual_ends_at' => $ends,
                'started_by' => $worker->id,
                'completed_by' => $worker->id,
                'expected_break_minutes' => 30,
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
