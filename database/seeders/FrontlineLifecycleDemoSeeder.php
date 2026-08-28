<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ClientRisk;
use App\Models\MedicationRound;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\Staff;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

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

        if (! $worker || ! $admin) {
            return;
        }

        $site = Site::updateOrCreate(
            ['name' => 'Playwright Lifecycle House'],
            [
                'type' => 'house',
                'address_line_1' => '2 Playwright Way',
                'suburb' => 'Eden Terrace',
                'city' => 'Auckland',
                'postcode' => '1021',
                'country' => 'New Zealand',
                'is_active' => true,
            ],
        );

        $serviceContext = ServiceContext::firstOrCreate(
            [
                'type' => 'residential',
                'site_id' => $site->id,
            ],
            [
                'name' => 'Frontline Lifecycle Readiness',
                'description' => 'Deterministic desktop readiness context',
                'is_active' => true,
            ],
        );

        $client = Client::query()
            ->where('site_id', $site->id)
            ->where('status', 'active')
            ->whereHas('supportWorkers', fn ($query) => $query->where('users.id', $worker->id))
            ->first()
            ?? Client::query()
                ->where('site_id', $site->id)
                ->where('status', 'active')
                ->first()
            ?? Client::withoutEvents(fn () => Client::updateOrCreate(
                [
                    'first_name' => 'Playwright',
                    'last_name' => 'Lifecycle',
                ],
                [
                    'site_id' => $site->id,
                    'service_context_id' => $serviceContext->id,
                    'preferred_name' => 'PW Lifecycle',
                    'date_of_birth' => now()->subYears(40)->toDateString(),
                    'gender' => 'not_stated',
                    'status' => 'active',
                    'city' => $site->city ?: 'Auckland',
                    'funding_type' => 'demo',
                ],
            ));
        $client->supportWorkers()->syncWithoutDetaching([$worker->id]);

        // Site access: TimesheetController::assertCanAccessTimesheet checks
        // hrEmployeeProfile.primary_site_id (or secondary_site_ids / user.site_id)
        // against the timesheet's site. Without a matching site assignment the
        // worker hits 403 on update/submit/resubmit. Grant sw1 access to the
        // demo client's site so the lifecycle flow is exercisable end-to-end.
        $this->grantSiteAccess($worker, $client);

        $this->seedReturnedTimesheet($worker, $admin, $client, $serviceContext);
        $this->seedPreShiftBriefing($worker, $admin, $client, $serviceContext);

        $medsWorker = $this->ensureMedicationReadinessWorker();
        $medsWitness = $this->ensureMedicationReadinessWitness();
        $medsServiceContext = ServiceContext::updateOrCreate(
            ['name' => 'PW Medication Readiness'],
            [
                'type' => 'residential',
                'is_active' => true,
            ],
        );
        $medsClient = $this->ensureMedicationReadinessClient($client, $medsServiceContext);
        $medsClient->supportWorkers()->syncWithoutDetaching([$medsWorker->id]);
        $this->grantSiteAccess($medsWorker, $medsClient);
        $this->grantSiteAccess($medsWitness, $medsClient);
        $restrictedMedsWorker = $this->ensureMedicationReadinessRestrictedWorker();
        $this->grantSiteAccess($restrictedMedsWorker, $medsClient);
        $this->seedMedicationReadinessFixtures($medsWorker, $admin, $medsClient, $medsServiceContext);

        $attendanceClient = $this->ensureAttendanceReadinessClient($client, $serviceContext);
        $this->grantSiteAccess($admin, $attendanceClient);
        $this->seedPlaywrightAttendanceFixtures($admin, $attendanceClient, $serviceContext);
    }

    private function ensureMedicationReadinessWorker(): User
    {
        $worker = User::updateOrCreate(
            ['email' => 'sw-meds@demo.test'],
            [
                'name' => 'Medication Demo Worker',
                'password' => Hash::make('password'),
                'role' => 'support_worker',
                'approved_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $worker->roles()->syncWithoutDetaching([$role->id]);
        }

        Staff::updateOrCreate(
            ['user_id' => $worker->id],
            [
                'employee_id' => 'SWMEDS',
                'job_title' => 'Support Worker',
                'department' => 'Clinical',
                'status' => 'active',
                'hire_date' => now()->subYear(),
            ],
        );

        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $worker->id],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'employee_number' => 'SWMEDS',
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'start_date' => now()->subYear()->toDateString(),
                'is_active' => true,
                'updated_by' => $worker->id,
                'created_by' => $worker->id,
            ],
        );

        return $worker;
    }

    private function ensureMedicationReadinessWitness(): User
    {
        $worker = User::updateOrCreate(
            ['email' => 'sw-meds-witness@demo.test'],
            [
                'name' => 'Medication Demo Witness',
                'password' => Hash::make('password'),
                'role' => 'support_worker',
                'approved_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $worker->roles()->syncWithoutDetaching([$role->id]);
        }

        Staff::updateOrCreate(
            ['user_id' => $worker->id],
            [
                'employee_id' => 'SWMEDSWIT',
                'job_title' => 'Support Worker',
                'department' => 'Clinical',
                'status' => 'active',
                'hire_date' => now()->subYear(),
            ],
        );

        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $worker->id],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'employee_number' => 'SWMEDSWIT',
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'start_date' => now()->subYear()->toDateString(),
                'is_active' => true,
                'updated_by' => $worker->id,
                'created_by' => $worker->id,
            ],
        );

        return $worker;
    }

    private function ensureMedicationReadinessRestrictedWorker(): User
    {
        $worker = User::updateOrCreate(
            ['email' => 'sw-meds-no-record@demo.test'],
            [
                'name' => 'Medication Restricted Worker',
                'password' => Hash::make('password'),
                'role' => 'support_worker',
                'approved_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        $worker->roles()->sync([]);

        Staff::updateOrCreate(
            ['user_id' => $worker->id],
            [
                'employee_id' => 'SWMEDSNO',
                'job_title' => 'Support Worker',
                'department' => 'Clinical',
                'status' => 'active',
                'hire_date' => now()->subYear(),
            ],
        );

        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $worker->id],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'employee_number' => 'SWMEDSNO',
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'start_date' => now()->subYear()->toDateString(),
                'is_active' => true,
                'updated_by' => $worker->id,
                'created_by' => $worker->id,
            ],
        );

        return $worker;
    }

    private function ensureMedicationReadinessClient(Client $fallbackClient, ServiceContext $serviceContext): Client
    {
        return Client::withoutEvents(fn () => Client::updateOrCreate(
            [
                'first_name' => 'Playwright',
                'last_name' => 'Meds',
            ],
            [
                'site_id' => $fallbackClient->site_id,
                'service_context_id' => $serviceContext->id,
                'preferred_name' => 'PW Meds',
                'date_of_birth' => now()->subYears(42)->toDateString(),
                'gender' => 'not_stated',
                'status' => 'active',
                'city' => $fallbackClient->city ?: 'Auckland',
                'funding_type' => 'demo',
            ],
        ));
    }

    private function ensureAttendanceReadinessClient(Client $fallbackClient, ServiceContext $serviceContext): Client
    {
        $site = Site::updateOrCreate(
            ['name' => 'Playwright Attendance House'],
            [
                'tenant_id' => (int) ($fallbackClient->tenant_id ?? 1),
                'type' => 'house',
                'address_line_1' => '1 Playwright Way',
                'suburb' => 'Eden Terrace',
                'city' => 'Auckland',
                'postcode' => '1021',
                'country' => 'New Zealand',
                'is_active' => true,
                'archived' => false,
                'archived_at' => null,
            ],
        );

        $client = Client::withoutEvents(fn () => Client::updateOrCreate(
            [
                'first_name' => 'Playwright',
                'last_name' => 'Attendance',
            ],
            [
                'site_id' => $site->id,
                'service_context_id' => $serviceContext->id,
                'preferred_name' => 'PW Attendance',
                'date_of_birth' => now()->subYears(40)->toDateString(),
                'gender' => 'not_stated',
                'status' => 'active',
                'city' => $fallbackClient->city ?: 'Auckland',
                'funding_type' => 'demo',
            ],
        ));

        // SystemMedicationsSeeder may attach broad demo medication coverage
        // after the first lifecycle-seeder pass. Attendance readiness needs a
        // genuinely clean clinical state, so cease only this named fixture's
        // medication rows while leaving the real unsigned-MAR gate intact.
        ClientMedication::query()
            ->where('client_id', $client->id)
            ->update([
                'active' => false,
                'state' => 'ceased',
                'end_date' => now()->subDay()->toDateString(),
                'ceased_at' => now(),
                'ceased_reason' => 'Playwright attendance readiness fixture has no scheduled medications.',
            ]);

        return $client;
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
        $this->clearOverlappingSeededAttendance(
            $worker,
            $shift->actual_starts_at,
            $shift->actual_ends_at,
            $shift->id,
        );

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
            'returned_notes' => 'Please double-check the break minutes — payroll rules require at least 30 min for an 8-hour shift, and add the kilometres if you used your own vehicle.',
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
            'notes' => 'Routine support shift. Family will visit between 14:00 and 15:00 — please greet them and update the visit log.',
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

    private function seedMedicationReadinessFixtures(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $now = Carbon::now($timezone);
        $anchor = $now->copy();

        // The broad demo seeders also attach example prescriptions to active
        // clients. Keep this named browser fixture deterministic by ceasing
        // only unrelated prescriptions for the exact Playwright meds client.
        ClientMedication::query()
            ->where('client_id', $client->id)
            ->where('name', 'not like', 'PW Meds %')
            ->update([
                'active' => false,
                'state' => 'ceased',
                'end_date' => $now->copy()->subDay()->toDateString(),
                'ceased_at' => $now,
                'ceased_reason' => 'Excluded from the deterministic Playwright medication round.',
                'ceased_by' => $admin->id,
            ]);

        if ($anchor->hour === 0 && $anchor->minute < 30) {
            // The first slot is anchor minus 15 minutes. Keep it on the
            // current local day and already due during the early-midnight
            // test window, rather than making every fixture slot future.
            $anchor->setTime(0, 15);
        } elseif ($anchor->hour === 23 && $anchor->minute > 30) {
            $anchor->setTime(23, 30);
        }

        $doseTimes = [
            $anchor->copy()->subMinutes(15)->format('H:i'),
            $anchor->copy()->format('H:i'),
            $anchor->copy()->addMinutes(15)->format('H:i'),
        ];

        $medications = collect([
            $this->upsertMedicationFixture($client, [
                'name' => 'PW Meds Morning Tablets',
                'dosage' => '1 tablet',
                'frequency' => 'Three times daily',
                'dose_times' => [$doseTimes[0]],
                'route' => 'oral',
                'form' => 'tablet',
                'instructions' => 'Give with water.',
            ]),
            $this->upsertMedicationFixture($client, [
                'name' => 'PW Meds Vitamin D',
                'dosage' => '1 capsule',
                'frequency' => 'Daily',
                'dose_times' => [$doseTimes[1]],
                'route' => 'oral',
                'form' => 'capsule',
                'instructions' => 'Give after breakfast.',
            ]),
            $this->upsertMedicationFixture($client, [
                'name' => 'PW Meds Eye Drops',
                'dosage' => '1 drop',
                'frequency' => 'Daily',
                'dose_times' => [$doseTimes[2]],
                'route' => 'ophthalmic',
                'form' => 'drops',
                'instructions' => 'Right eye.',
            ]),
            $this->upsertMedicationFixture($client, [
                'name' => 'PW Meds PRN Paracetamol',
                'dosage' => '500mg',
                'frequency' => 'As needed',
                'dose_times' => [],
                'route' => 'oral',
                'form' => 'tablet',
                'instructions' => 'Offer fluids after administration.',
                'is_prn' => true,
                'prn_reason' => 'Pain|Headache',
                'max_per_day' => 4,
                'min_hours_between_doses' => 1,
            ]),
            $this->upsertMedicationFixture($client, [
                'name' => 'PW Meds Controlled PRN',
                'dosage' => '1 capsule',
                'frequency' => 'As needed',
                'dose_times' => [],
                'route' => 'oral',
                'form' => 'capsule',
                'instructions' => 'Requires a second staff witness.',
                'is_prn' => true,
                'prn_reason' => 'Severe pain',
                'max_per_day' => 2,
                'min_hours_between_doses' => 4,
                'controlled_drug' => true,
                'witness_required' => true,
            ]),
        ]);

        ClientMedicationAdministration::query()
            ->whereIn('client_medication_id', $medications->pluck('id')->all())
            ->forceDelete();
        ClientControlledDrugEntry::query()
            ->whereIn('client_medication_id', $medications->pluck('id')->all())
            ->delete();

        ClientRisk::updateOrCreate(
            [
                'client_id' => $client->id,
                'label' => 'PW Meds active mobility risk',
            ],
            [
                'severity' => 'high',
                'controls' => 'Use two-person support for transfers.',
                'review_date' => now()->addMonth()->toDateString(),
                'active' => true,
            ],
        );

        $controlledPrn = $medications->firstWhere('name', 'PW Meds Controlled PRN');
        if ($controlledPrn) {
            ClientMedicationStock::updateOrCreate(
                ['client_medication_id' => $controlledPrn->id],
                [
                    'on_hand' => 12,
                    'unit' => 'capsules',
                    'reorder_level' => 2,
                    'last_counted_at' => now(),
                ],
            );
        }

        $startsAt = $now->copy()->subHour()->utc();
        $endsAt = $now->copy()->addHours(5)->utc();
        $shift = $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:meds-readiness:active-shift',
            $startsAt,
            $endsAt,
            'in_progress',
            [
                'actual_starts_at' => $startsAt,
                'started_by' => $worker->id,
                'expected_break_minutes' => 0,
            ],
        );

        $this->clearOverlappingSeededAttendance($worker, $startsAt, null, $shift->id);

        HrAttendanceSession::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'site_id' => $shift->site_id,
                'clock_in_at' => $startsAt,
                'clock_out_at' => null,
                'break_minutes' => 0,
                'status' => 'open',
                'source' => 'playwright',
                'created_by' => $worker->id,
                'closed_by' => null,
            ],
        );

        MedicationRound::updateOrCreate(
            [
                'name' => 'PW Meds Readiness Round',
                'round_date' => $now->toDateString(),
                'assigned_to' => $worker->id,
            ],
            [
                'service_context_id' => $serviceContext->id,
                'site_id' => $client->site_id,
                'round_type' => 'scheduled',
                'scheduled_time' => $anchor->format('H:i'),
                'window_minutes' => 120,
                'status' => 'pending',
                'started_by' => null,
                'completed_by' => null,
                'started_at' => null,
                'completed_at' => null,
                'total_medications' => 3,
                'administered_count' => 0,
                'refused_count' => 0,
                'withheld_count' => 0,
                'missed_count' => 0,
                'notes' => 'Playwright readiness guided round.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function upsertMedicationFixture(Client $client, array $overrides): ClientMedication
    {
        $attributes = array_merge([
            'client_id' => $client->id,
            'dosage' => null,
            'frequency' => null,
            'dose_times' => [],
            'is_prn' => false,
            'controlled_drug' => false,
            'high_risk' => false,
            'witness_required' => false,
            'prn_reason' => null,
            'max_per_day' => null,
            'min_hours_between_doses' => null,
            'route' => null,
            'form' => null,
            'instructions' => null,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'active' => true,
            'state' => 'active',
            'version' => 1,
        ], $overrides);

        $medication = ClientMedication::withTrashed()
            ->where('client_id', $client->id)
            ->where('name', $attributes['name'])
            ->first();

        if ($medication) {
            $medication->forceFill(array_merge($attributes, ['deleted_at' => null]))->save();

            return $medication->fresh() ?? $medication;
        }

        return ClientMedication::create($attributes);
    }

    private function seedPlaywrightAttendanceFixtures(User $admin, Client $client, ServiceContext $serviceContext): void
    {
        $workers = User::query()
            ->whereIn('email', [
                'sw2@demo.test',
                'sw3@demo.test',
                'sw4@demo.test',
                'sw5@demo.test',
                'sw6@demo.test',
                'sw7@demo.test',
                'sw8@demo.test',
            ])
            ->get()
            ->keyBy('email');

        foreach ($workers as $worker) {
            $this->grantSiteAccess($worker, $client);
        }

        $incomingWorker = $workers->get('sw8@demo.test');
        if (! $incomingWorker) {
            $this->command?->warn('sw8@demo.test not found — attendance handover readiness fixtures were skipped.');

            return;
        }
        $incomingHandoverShift = $this->seedIncomingHandoverShift(
            $incomingWorker,
            $admin,
            $client,
            $serviceContext,
        );

        foreach (['sw2@demo.test', 'sw6@demo.test'] as $email) {
            if ($workers->has($email)) {
                $this->seedActiveClockOutClean(
                    $workers[$email],
                    $admin,
                    $client,
                    $serviceContext,
                    $incomingHandoverShift,
                );
                $this->seedSubmittedApprovalTimesheet($workers[$email], $admin, $client, $serviceContext);
            }
        }

        foreach (['sw3@demo.test', 'sw8@demo.test'] as $email) {
            if ($workers->has($email)) {
                $this->seedClockInCandidate($workers[$email], $admin, $client, $serviceContext);
            }
        }

        if ($workers->has('sw4@demo.test')) {
            $this->seedActiveChecklistShift($workers['sw4@demo.test'], $admin, $client, $serviceContext);
        }

        if ($workers->has('sw7@demo.test')) {
            $this->seedActiveChecklistShift($workers['sw7@demo.test'], $admin, $client, $serviceContext);
        }

        if ($workers->has('sw5@demo.test')) {
            $this->seedActiveIncidentBlockerShift(
                $workers['sw5@demo.test'],
                $admin,
                $client,
                $serviceContext,
                $incomingHandoverShift,
            );
        }
    }

    private function seedIncomingHandoverShift(
        User $worker,
        User $admin,
        Client $client,
        ServiceContext $serviceContext,
    ): Shift {
        // Keep this after the clock-in candidate's seven-hour window while
        // remaining within the hardened handover boundary (outgoing end + 12h).
        $startsAt = Carbon::now()->addHours(8)->startOfMinute();

        return $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:incoming-handover:'.$worker->email,
            $startsAt,
            $startsAt->copy()->addHours(8),
            'scheduled',
        );
    }

    private function seedActiveClockOutClean(
        User $worker,
        User $admin,
        Client $client,
        ServiceContext $serviceContext,
        Shift $incomingHandoverShift,
    ): void {
        $startsAt = Carbon::now()->subHours(2)->startOfMinute();
        $endsAt = Carbon::now()->addHours(6)->startOfMinute();
        $shift = $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:active-clean:'.$worker->email,
            $startsAt,
            $endsAt,
            'in_progress',
            [
                'actual_starts_at' => $startsAt,
                'started_by' => $worker->id,
                'expected_break_minutes' => 0,
            ],
        );

        $shift->tasks()->delete();

        $this->clearOverlappingSeededAttendance($worker, $startsAt, null, $shift->id);

        HrAttendanceSession::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'site_id' => $shift->site_id,
                'clock_in_at' => $startsAt,
                'clock_out_at' => null,
                'break_minutes' => 0,
                'status' => 'open',
                'source' => 'playwright',
                'created_by' => $worker->id,
                'closed_by' => null,
            ],
        );

        $this->ensureSubmittedPlaywrightHandover($shift, $worker, [
            'incoming_shift_id' => $incomingHandoverShift->id,
            'handover_notes' => 'Playwright clean handover already submitted.',
            'client_mood' => 'calm',
            'submit' => true,
        ]);
    }

    private function seedClockInCandidate(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        HrAttendanceSession::query()
            ->where('user_id', $worker->id)
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'clock_out_at' => Carbon::now()->subMinutes(10),
            ]);

        $startsAt = Carbon::now()->subMinutes(20)->startOfMinute();
        $endsAt = Carbon::now()->addHours(7)->startOfMinute();

        $shift = $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:clock-in-candidate:'.$worker->email,
            $startsAt,
            $endsAt,
            'scheduled',
        );

        $shift->tasks()->delete();
    }

    private function seedActiveChecklistShift(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        $startsAt = Carbon::now()->subHours(2)->startOfMinute();
        $endsAt = Carbon::now()->addHours(6)->startOfMinute();
        $shift = $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:active-checklist:'.$worker->email,
            $startsAt,
            $endsAt,
            'in_progress',
            [
                'actual_starts_at' => $startsAt,
                'started_by' => $worker->id,
                'expected_break_minutes' => 0,
            ],
        );

        $shift->outgoingHandovers()->delete();
        $shift->tasks()->delete();
        ShiftTask::create([
            'shift_id' => $shift->id,
            'label' => 'Playwright checklist task',
            'sort_order' => 1,
            'is_completed' => false,
        ]);

        $this->clearOverlappingSeededAttendance($worker, $startsAt, null, $shift->id);

        HrAttendanceSession::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'site_id' => $shift->site_id,
                'clock_in_at' => $startsAt,
                'clock_out_at' => null,
                'break_minutes' => 0,
                'status' => 'open',
                'source' => 'playwright',
                'created_by' => $worker->id,
                'closed_by' => null,
            ],
        );
    }

    private function seedActiveIncidentBlockerShift(
        User $worker,
        User $admin,
        Client $client,
        ServiceContext $serviceContext,
        Shift $incomingHandoverShift,
    ): void {
        $startsAt = Carbon::now()->subHours(2)->startOfMinute();
        $endsAt = Carbon::now()->addHours(6)->startOfMinute();
        $shift = $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:active-incident-blocker:'.$worker->email,
            $startsAt,
            $endsAt,
            'in_progress',
            [
                'actual_starts_at' => $startsAt,
                'started_by' => $worker->id,
                'expected_break_minutes' => 0,
            ],
        );

        $shift->tasks()->delete();

        $this->clearOverlappingSeededAttendance($worker, $startsAt, null, $shift->id);

        HrAttendanceSession::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'site_id' => $shift->site_id,
                'clock_in_at' => $startsAt,
                'clock_out_at' => null,
                'break_minutes' => 0,
                'status' => 'open',
                'source' => 'playwright',
                'created_by' => $worker->id,
                'closed_by' => null,
            ],
        );

        ClientIncident::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'reported_by' => $worker->id,
                'title' => 'Playwright draft incident',
            ],
            [
                'client_id' => $client->id,
                'service_context_id' => $serviceContext->id,
                'type' => 'behaviour',
                'severity' => 'low',
                'status' => 'draft',
                'occurred_at' => Carbon::now()->subHour(),
                'description' => 'Draft incident left open for blocker feedback coverage.',
            ],
        );

        $this->ensureSubmittedPlaywrightHandover($shift, $worker, [
            'incoming_shift_id' => $incomingHandoverShift->id,
            'handover_notes' => 'Playwright incident blocker handover already submitted.',
            'client_mood' => 'mixed',
            'submit' => true,
        ]);
    }

    private function seedSubmittedApprovalTimesheet(User $worker, User $admin, Client $client, ServiceContext $serviceContext): void
    {
        $workDate = Carbon::now()->subDays($worker->email === 'sw2@demo.test' ? 3 : 4)->startOfDay();
        $startsAt = $workDate->copy()->setTime(8, 0);
        $endsAt = $workDate->copy()->setTime(16, 0);
        $shift = $this->upsertPlaywrightShift(
            $worker,
            $admin,
            $client,
            $serviceContext,
            'PW:submitted-approval:'.$worker->email,
            $startsAt,
            $endsAt,
            'completed',
            [
                'actual_starts_at' => $startsAt,
                'actual_ends_at' => $endsAt,
                'started_by' => $worker->id,
                'completed_by' => $worker->id,
                'expected_break_minutes' => 30,
            ],
        );

        $this->clearOverlappingSeededAttendance($worker, $startsAt, $endsAt, $shift->id);

        $attendance = HrAttendanceSession::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'tenant_id' => $worker->tenant_id ?? 1,
                'site_id' => $shift->site_id,
                'clock_in_at' => $startsAt,
                'clock_out_at' => $endsAt,
                'break_minutes' => 30,
                'status' => 'closed',
                'source' => 'playwright',
                'created_by' => $worker->id,
                'closed_by' => $worker->id,
            ],
        );

        Timesheet::updateOrCreate(
            [
                'shift_id' => $shift->id,
                'user_id' => $worker->id,
            ],
            [
                'attendance_session_id' => $attendance->id,
                'client_id' => $client->id,
                'shift_site_id' => $shift->site_id,
                'shift_service_context_id' => $shift->service_context_id,
                'work_date' => $workDate->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'break_minutes' => 30,
                'notes' => 'Playwright submitted timesheet.',
                'status' => 'submitted',
                'submitted_at' => Carbon::now()->subHour(),
                'submitted_by' => $worker->id,
                'created_by' => $worker->id,
                'shift_site_name_snapshot' => $shift->site?->name ?? 'Demo Site',
                'service_context_name_snapshot' => $serviceContext->name,
                'client_name_snapshot' => trim($client->first_name.' '.$client->last_name),
                'staff_name_snapshot' => $worker->name,
                'shift_type_snapshot' => 'standard',
                'coverage_roles_snapshot' => [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function upsertPlaywrightShift(
        User $worker,
        User $admin,
        Client $client,
        ServiceContext $serviceContext,
        string $notes,
        Carbon $startsAt,
        Carbon $endsAt,
        string $status,
        array $overrides = [],
    ): Shift {
        $shift = Shift::query()->where('notes', $notes)->first();
        $attributes = array_merge([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => $client->city ?: 'Auckland',
            'status' => $status,
            'notes' => $notes,
            'created_by' => $admin->id,
        ], $overrides);

        if ($shift) {
            // A previous demo / e2e run may have approved this fixture
            // shift's timesheet, which locks payroll-critical fields on the
            // shift (Shift::updating guard) and breaks the deterministic
            // re-seed. These PW:* shifts exist only for Playwright, so
            // quietly un-approve their timesheets before moving the shift.
            $shift->timesheets()
                ->where('status', 'approved')
                ->get()
                ->each(function (Timesheet $timesheet): void {
                    $timesheet->forceFill([
                        'status' => 'submitted',
                        'approved_at' => null,
                        'approved_by' => null,
                    ])->saveQuietly();
                });

            $shift->forceFill($attributes)->save();
        } else {
            $shift = Shift::create($attributes);
        }

        $shift->forceFill(['published_at' => Carbon::now()])->save();

        return $shift->fresh(['site']) ?? $shift;
    }

    private function clearOverlappingSeededAttendance(
        User $worker,
        ?Carbon $startsAt,
        ?Carbon $endsAt,
        ?int $exceptShiftId = null,
    ): void {
        if (! $startsAt) {
            return;
        }

        $candidateEnd = $endsAt ?? Carbon::now()->addYears(10);

        HrAttendanceSession::query()
            ->where('user_id', $worker->id)
            ->whereIn('source', ['seeder', 'playwright'])
            ->when($exceptShiftId, fn ($query) => $query->where('shift_id', '!=', $exceptShiftId))
            ->whereNotNull('clock_in_at')
            ->where('clock_in_at', '<', $candidateEnd)
            ->where(function ($query) use ($startsAt) {
                $query->whereNull('clock_out_at')
                    ->orWhere('clock_out_at', '>', $startsAt);
            })
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureSubmittedPlaywrightHandover(Shift $shift, User $worker, array $payload): void
    {
        $existing = $shift->outgoingHandovers()
            ->whereIn('status', [
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ])
            ->first();

        if ($existing) {
            $incomingShiftId = filter_var(
                $payload['incoming_shift_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $incomingShift = $incomingShiftId === false
                ? null
                : Shift::query()
                    ->whereKey($incomingShiftId)
                    ->where('client_id', $shift->client_id)
                    ->where('site_id', $shift->site_id)
                    ->where('service_context_id', $shift->service_context_id)
                    ->whereNotNull('user_id')
                    ->first(['id', 'user_id']);

            if (! $incomingShift
                || (int) $existing->client_id !== (int) $shift->client_id
                || (int) $existing->outgoing_staff_id !== (int) $worker->id) {
                throw new \LogicException('The deterministic Playwright handover fixture has conflicting canonical identity.');
            }

            if ((int) $existing->incoming_shift_id !== (int) $incomingShift->id
                || (int) $existing->incoming_staff_id !== (int) $incomingShift->user_id) {
                // Older demo databases already contain submitted handovers from
                // before exact incoming-Shift binding. Repair only the two
                // deterministic fixture identities; retain lifecycle, version,
                // timestamps, acknowledgement, and authored clinical content.
                $existing->timestamps = false;
                $existing->forceFill([
                    'incoming_shift_id' => $incomingShift->id,
                    'incoming_staff_id' => $incomingShift->user_id,
                ])->save();
            }

            return;
        }

        app(ShiftHandoverService::class)->save($shift, $worker, $payload);
    }
}
