<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function hrRoleUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

function grantHrTimePermission(User $user, string $permissionKey): void
{
    $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
}

function denyHrTimePermission(User $user, string $permissionKey): void
{
    $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => false],
    ]);
}

function hrTimeProfile(User $user, ?User $manager = null, ?Site $site = null): void
{
    $site ??= Site::query()->first() ?? Site::factory()->create();

    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-HRT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => $user->role,
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'manager_user_id' => $manager?->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
}

function hrScopedTimeEntry(User $staff, array $overrides = []): HrTimeEntry
{
    $siteId = $staff->hrEmployeeProfile?->primary_site_id;
    if (! $siteId) {
        $siteId = Site::query()->firstOrFail()->id;
        $staff->hrEmployeeProfile()->update(['primary_site_id' => $siteId]);
    }

    return HrTimeEntry::factory()->create(array_merge([
        'user_id' => $staff->id,
        'site_id' => $siteId,
    ], $overrides));
}

function hrSharedOperationsTimesheet(User $staff, array $overrides = []): Timesheet
{
    $siteId = $staff->hrEmployeeProfile?->primary_site_id;
    if (! $siteId) {
        $siteId = Site::query()->firstOrFail()->id;
        $staff->hrEmployeeProfile()->update(['primary_site_id' => $siteId]);
    }
    $client = Client::factory()->create([
        'first_name' => 'Aroha',
        'last_name' => 'Ngata',
        'status' => 'active',
        'site_id' => $siteId,
    ]);
    $startsAt = CarbonImmutable::parse('2026-04-20 09:00:00', config('app.worker_timezone'))->utc();
    $endsAt = $startsAt->addHours(8);

    return Timesheet::query()->create(array_merge([
        'user_id' => $staff->id,
        'client_id' => $client->id,
        'site_id' => $siteId,
        'work_date' => '2026-04-20',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 30,
        'status' => 'submitted',
        'submitted_at' => now()->subHour(),
        'submitted_by' => $staff->id,
        'created_by' => $staff->id,
        'client_name_snapshot' => 'Aroha Ngata',
        'staff_name_snapshot' => $staff->name,
        'shift_type_snapshot' => 'standard',
        'coverage_roles_snapshot' => [],
    ], $overrides));
}

test('users without hr time permission cannot clock in via hr time routes', function () {
    $user = hrRoleUser('support_worker');

    $this->actingAs($user)
        ->post('/hr/time/clock-in')
        ->assertForbidden();
});

test('hr users can access the hr time dashboard', function () {
    $user = hrRoleUser('hr');
    hrTimeProfile($user);

    $this->actingAs($user)
        ->get('/hr/time')
        ->assertOk();
});

test('former staff cannot retain hr time access through an old permission', function () {
    $user = hrRoleUser('hr');
    hrTimeProfile($user);
    HrEmployeeProfile::query()->where('user_id', $user->id)->update([
        'end_date' => today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get('/hr/time')
        ->assertForbidden();
});

test('approve-only managers see only direct reports at their approved Sites across reads and exports', function () {
    $localSite = Site::factory()->create(['name' => 'Local attendance Site']);
    $foreignSite = Site::factory()->create(['name' => 'Foreign attendance Site']);
    $lead = hrRoleUser('team_lead');
    $report = hrRoleUser('support_worker');
    $foreignReport = hrRoleUser('support_worker');
    $stranger = hrRoleUser('support_worker');
    hrTimeProfile($lead, site: $localSite);
    hrTimeProfile($report, $lead, $localSite);
    hrTimeProfile($foreignReport, $lead, $foreignSite);
    hrTimeProfile($stranger, site: $localSite);
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');
    denyHrTimePermission($lead, 'timesheets.manageAny');
    denyHrTimePermission($lead, 'reports.viewAny');
    $localClient = Client::factory()->create(['site_id' => $localSite->id]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);

    $visible = hrScopedTimeEntry($report, [
        'status' => 'submitted',
        'notes' => 'Visible team entry',
    ]);
    $privateStranger = hrScopedTimeEntry($stranger, [
        'status' => 'submitted',
        'notes' => 'Private stranger entry',
    ]);
    $privateForeign = hrScopedTimeEntry($foreignReport, [
        'status' => 'submitted',
        'notes' => 'Private foreign team entry',
    ]);

    $this->actingAs($lead)
        ->get('/hr/time?scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'team')
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $visible->id)
            ->where('teamMembers', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$report->id])
            ->where('sites', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localSite->id])
            ->where('clients', fn ($rows): bool => collect($rows)->pluck('id')->contains($localClient->id)
                && ! collect($rows)->pluck('id')->contains($foreignClient->id)));

    $this->actingAs($lead)
        ->get('/hr/time/export?scope=all')
        ->assertForbidden();

    $this->actingAs($lead)
        ->get("/hr/time/entries/{$visible->id}/amendments")
        ->assertOk();
    $this->actingAs($lead)
        ->get("/hr/time/entries/{$privateStranger->id}/amendments")
        ->assertNotFound();
    $this->actingAs($lead)
        ->get("/hr/time/entries/{$privateForeign->id}/amendments")
        ->assertNotFound();
});

test('manageAny remains Site-local while reports viewAny opens every canonical attendance Site', function () {
    $localSite = Site::factory()->create(['name' => 'Manager local Site']);
    $foreignSite = Site::factory()->create(['name' => 'Manager foreign Site']);
    $localManager = hrRoleUser('hr');
    $globalManager = hrRoleUser('hr');
    $localStaff = hrRoleUser('support_worker');
    $foreignStaff = hrRoleUser('support_worker');
    hrTimeProfile($localManager, site: $localSite);
    hrTimeProfile($globalManager, site: $localSite);
    hrTimeProfile($localStaff, site: $localSite);
    hrTimeProfile($foreignStaff, site: $foreignSite);
    grantHrTimePermission($localManager, 'timesheets.viewAny');
    grantHrTimePermission($localManager, 'timesheets.manageAny');
    denyHrTimePermission($localManager, 'reports.viewAny');
    grantHrTimePermission($globalManager, 'timesheets.viewAny');
    grantHrTimePermission($globalManager, 'reports.viewAny');
    denyHrTimePermission($globalManager, 'timesheets.manageAny');
    denyHrTimePermission($globalManager, 'timesheets.approve');

    $localClient = Client::factory()->create(['site_id' => $localSite->id]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $localEntry = hrScopedTimeEntry($localStaff, [
        'entry_date' => now()->toDateString(),
        'total_hours' => 4,
        'notes' => 'Local manager-visible entry',
    ]);
    $foreignEntry = hrScopedTimeEntry($foreignStaff, [
        'entry_date' => now()->toDateString(),
        'total_hours' => 40,
        'notes' => 'Foreign manager-private entry',
    ]);
    $nonCanonicalEntry = hrScopedTimeEntry($localStaff, [
        'client_id' => $foreignClient->id,
        'entry_date' => now()->toDateString(),
        'total_hours' => 400,
        'notes' => 'Contradictory Client and Site provenance',
    ]);
    $localTimesheet = hrSharedOperationsTimesheet($localStaff);
    $foreignTimesheet = hrSharedOperationsTimesheet($foreignStaff);

    $this->actingAs($localManager)
        ->get('/hr/time?scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localEntry->id])
            ->where('timesheets.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localTimesheet->id])
            ->where('teamMembers', fn ($rows): bool => collect($rows)->pluck('id')->contains($localStaff->id)
                && ! collect($rows)->pluck('id')->contains($foreignStaff->id))
            ->where('sites', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localSite->id])
            ->where('clients', fn ($rows): bool => collect($rows)->pluck('id')->contains($localClient->id)
                && ! collect($rows)->pluck('id')->contains($foreignClient->id))
            ->where('kpiStats.team_hours_week', fn ($hours): bool => (float) $hours === 4.0));

    $this->actingAs($localManager)
        ->get('/hr/time?tab=reports&scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report', null)
            ->where('can.reportAny', false));
    $this->actingAs($localManager)
        ->get('/hr/time/report/pdf')
        ->assertForbidden();

    $this->actingAs($localManager)
        ->get('/hr/time/export?scope=all')
        ->assertForbidden();
    $this->actingAs($localManager)
        ->get("/hr/time/entries/{$localEntry->id}/amendments")
        ->assertOk();
    $this->actingAs($localManager)
        ->get("/hr/time/entries/{$foreignEntry->id}/amendments")
        ->assertNotFound();
    $this->actingAs($localManager)
        ->get("/hr/time/entries/{$nonCanonicalEntry->id}/amendments")
        ->assertNotFound();

    $this->actingAs($globalManager)
        ->get('/hr/time?scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$localEntry->id, $foreignEntry->id])->sort()->values()->all())
            ->where('timesheets.data', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$localTimesheet->id, $foreignTimesheet->id])->sort()->values()->all())
            ->where('filterSites', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$localSite->id, $foreignSite->id])->sort()->values()->all())
            ->where('sites', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localSite->id])
            ->where('clients', fn ($rows): bool => collect($rows)->pluck('id')->contains($localClient->id)
                && ! collect($rows)->pluck('id')->contains($foreignClient->id)));

    $this->actingAs($globalManager)
        ->get('/hr/time?tab=reports&scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.kpis.total_hours', fn ($hours): bool => (float) $hours === 44.0)
            ->where('report.by_staff', fn ($rows): bool => collect($rows)->pluck('user_id')->sort()->values()->all()
                === collect([$localStaff->id, $foreignStaff->id])->sort()->values()->all()));

    $globalCsv = $this->actingAs($globalManager)
        ->get('/hr/time/export?scope=all')
        ->assertOk()
        ->streamedContent();
    expect($globalCsv)->toContain($localStaff->name)
        ->toContain($foreignStaff->name);
    $this->actingAs($globalManager)
        ->get("/hr/time/entries/{$foreignEntry->id}/amendments")
        ->assertOk();
    $this->actingAs($globalManager)
        ->get("/hr/time/entries/{$nonCanonicalEntry->id}/amendments")
        ->assertNotFound();
});

test('report-wide reads never broaden mixed manager mutation choices or approval actions', function () {
    $localSite = Site::factory()->create(['name' => 'Mixed manager local Site']);
    $foreignSite = Site::factory()->create(['name' => 'Mixed manager report Site']);
    $manager = hrRoleUser('hr');
    $localStaff = hrRoleUser('support_worker');
    $foreignStaff = hrRoleUser('support_worker');
    hrTimeProfile($manager, site: $localSite);
    hrTimeProfile($localStaff, $manager, $localSite);
    hrTimeProfile($foreignStaff, site: $foreignSite);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'reports.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');
    grantHrTimePermission($manager, 'timesheets.approve');
    $localClient = Client::factory()->create(['site_id' => $localSite->id]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $clockIn = now()->subHours(13);
    $localEntry = hrScopedTimeEntry($localStaff, [
        'entry_date' => $clockIn->copy()->setTimezone(config('app.worker_timezone'))->toDateString(),
        'clock_in' => $clockIn,
        'clock_out' => null,
        'total_hours' => null,
        'break_compliance_met' => null,
        'status' => 'active',
    ]);
    $foreignEntry = hrScopedTimeEntry($foreignStaff, [
        'entry_date' => $clockIn->copy()->setTimezone(config('app.worker_timezone'))->toDateString(),
        'clock_in' => $clockIn,
        'clock_out' => null,
        'total_hours' => null,
        'break_compliance_met' => null,
        'status' => 'active',
    ]);
    $localTimesheet = hrSharedOperationsTimesheet($localStaff);
    $foreignTimesheet = hrSharedOperationsTimesheet($foreignStaff);

    $this->actingAs($manager)
        ->get('/hr/time?scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data', function ($rows) use ($localEntry, $foreignEntry): bool {
                $byId = collect($rows)->keyBy('id');

                return $byId->get($localEntry->id)['can_mutate'] === true
                    && $byId->get($foreignEntry->id)['can_mutate'] === false;
            })
            ->where('timesheets.data', fn ($rows): bool => collect($rows)->pluck('id')->contains($localTimesheet->id)
                && collect($rows)->pluck('id')->contains($foreignTimesheet->id))
            ->where('onNow', function ($rows) use ($localEntry, $foreignEntry): bool {
                $byId = collect($rows)->keyBy('id');

                return $byId->get($localEntry->id)['can_mutate'] === true
                    && $byId->get($foreignEntry->id)['can_mutate'] === false;
            })
            ->where('exceptions', function ($rows) use ($localEntry, $foreignEntry): bool {
                $byId = collect($rows)->keyBy('id');

                return $byId->get('missed-'.$localEntry->id)['can_mutate'] === true
                    && $byId->get('missed-'.$foreignEntry->id)['can_mutate'] === false;
            })
            ->where('approvalTimesheets', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localTimesheet->id])
            ->where('pendingApprovalCount', 1)
            ->where('kpiStats.awaiting_approval', 1)
            ->where('filterSites', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$localSite->id, $foreignSite->id])->sort()->values()->all())
            ->where('sites', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$localSite->id])
            ->where('clients', fn ($rows): bool => collect($rows)->pluck('id')->contains($localClient->id)
                && ! collect($rows)->pluck('id')->contains($foreignClient->id))
            ->where('staff', fn ($rows): bool => collect($rows)->pluck('id')->contains($localStaff->id)
                && ! collect($rows)->pluck('id')->contains($foreignStaff->id)));
});

test('report readers see canonical current staff without receiving mutation or approval controls', function () {
    $site = Site::factory()->create(['name' => 'Reporting Site']);
    $reader = hrRoleUser('support_worker');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($reader, site: $site);
    hrTimeProfile($staff, site: $site);
    grantHrTimePermission($reader, 'timesheets.viewAny');
    grantHrTimePermission($reader, 'reports.viewAny');
    denyHrTimePermission($reader, 'timesheets.manageAny');
    denyHrTimePermission($reader, 'timesheets.approve');
    $entry = hrScopedTimeEntry($staff, ['entry_date' => now()->toDateString()]);
    $timesheet = hrSharedOperationsTimesheet($staff, ['status' => 'submitted']);

    $this->actingAs($reader)
        ->get('/hr/time?tab=reports&scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data', fn ($rows): bool => collect($rows)->pluck('id')->contains($entry->id))
            ->where('timesheets.data', fn ($rows): bool => collect($rows)->pluck('id')->contains($timesheet->id))
            ->where('can.reportAny', true)
            ->where('can.manage', false)
            ->where('can.approveAny', false)
            ->where('can.editEntry', false)
            ->where('can.clockOnBehalf', false)
            ->where('approvalTimesheets', []));

    $this->actingAs($reader)
        ->post('/hr/time/entries', [])
        ->assertForbidden();
});

test('hr time timesheets tab lists the shared operations timesheet rows', function () {
    $hr = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($hr);
    hrTimeProfile($staff);

    $timesheet = hrSharedOperationsTimesheet($staff, [
        'status' => 'draft',
        'submitted_at' => null,
        'submitted_by' => null,
    ]);

    $this->actingAs($hr)
        ->get('/hr/time?tab=timesheets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/time/index')
            ->where('timesheets.data.0.id', $timesheet->id)
            ->where('timesheets.data.0.source', 'operations')
            ->where('timesheets.data.0.module_url', '/operations/timesheets?view='.$timesheet->id)
            ->where('timesheets.data.0.user_name', $staff->name)
        );
});

test('hr time approval queue lists submitted operations timesheets', function () {
    $hr = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($hr);
    hrTimeProfile($staff);

    $timesheet = hrSharedOperationsTimesheet($staff);

    $this->actingAs($hr)
        ->get('/hr/time?tab=approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/time/index')
            ->where('pendingApprovalCount', 1)
            ->where('kpiStats.awaiting_approval', 1)
            ->where('approvalTimesheets.0.id', $timesheet->id)
            ->where('approvalTimesheets.0.source', 'operations')
            ->where('approvalTimesheets.0.module_url', '/operations/timesheets?tab=submitted&view='.$timesheet->id)
        );
});

test('legacy hr timesheet workflow routes are removed', function () {
    foreach ([
        'hr.time.timesheets.submit',
        'hr.time.timesheets.approve',
        'hr.time.timesheets.reject',
        'hr.time.timesheets.return',
        'hr.time.timesheets.bulk-approve',
        'hr.time.timesheets.bulk-reject',
        'hr.time.timesheets.bulk-return',
    ] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName))->toBeNull();
    }
});

test('hr time frontend links to operations timesheets instead of posting to legacy hr endpoints', function () {
    $source = file_get_contents(resource_path('js/pages/hr/time/index.tsx'));

    expect($source)->not->toContain('/hr/time/timesheets/');
    expect($source)->not->toContain('/hr/time/timesheets/bulk-');
    expect($source)->toContain('/operations/timesheets');
});

test('clock on behalf requires and persists a reason', function () {
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager);
    hrTimeProfile($staff, $manager);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');
    $client = Client::factory()->create([
        'site_id' => $manager->hrEmployeeProfile->primary_site_id,
        'status' => 'active',
    ]);

    // Missing reason → validation error.
    $this->actingAs($manager)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $staff->id,
            'clock_in' => '2026-04-20 09:00',
        ])
        ->assertSessionHasErrors(['reason']);

    // With reason → persisted to the entry + an audit amendment row.
    $this->actingAs($manager)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $staff->id,
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 17:00',
            'client_id' => $client->id,
            'break_minutes' => 30,
            'reason' => 'Staff forgot to clock in during an emergency handover.',
        ])
        ->assertSessionHasNoErrors();

    $entry = HrTimeEntry::query()
        ->where('user_id', $staff->id)
        ->where('entry_type', 'admin_clock')
        ->firstOrFail();

    expect($entry->amendment_reason)->toContain('emergency handover');
    expect($entry->amendments()->where('field_name', 'created_on_behalf')->exists())->toBeTrue();
});

test('approve-only create commands conceal stranger foreign and nonexistent targets without writes', function () {
    $localSite = Site::factory()->create(['name' => 'Approve command Site']);
    $foreignSite = Site::factory()->create(['name' => 'Approve foreign Site']);
    $lead = hrRoleUser('team_lead');
    $stranger = hrRoleUser('support_worker');
    $foreignReport = hrRoleUser('support_worker');
    hrTimeProfile($lead, site: $localSite);
    hrTimeProfile($stranger, site: $localSite);
    hrTimeProfile($foreignReport, $lead, $foreignSite);
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');
    denyHrTimePermission($lead, 'timesheets.manageAny');
    denyHrTimePermission($lead, 'reports.viewAny');
    $localClient = Client::factory()->create(['site_id' => $localSite->id]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $missingUserId = User::query()->max('id') + 1000;

    $targets = [
        [$stranger->id, $localClient->id],
        [$foreignReport->id, $foreignClient->id],
        [$missingUserId, $localClient->id],
    ];
    $commands = [
        fn (int $targetId, int $clientId): array => [
            '/hr/time/entries',
            [
                'user_id' => $targetId,
                'client_id' => $clientId,
                'clock_in' => '2026-04-20 09:00',
                'clock_out' => '2026-04-20 17:00',
            ],
        ],
        fn (int $targetId, int $clientId): array => [
            '/hr/time/clock-on-behalf',
            [
                'target_user_id' => $targetId,
                'client_id' => $clientId,
                'clock_in' => '2026-04-20 09:00',
                'clock_out' => '2026-04-20 17:00',
                'reason' => 'Concealed command-scope check.',
            ],
        ],
    ];

    foreach ($commands as $command) {
        foreach ($targets as [$targetId, $clientId]) {
            [$uri, $payload] = $command($targetId, $clientId);
            $entryCount = HrTimeEntry::query()->count();
            $timesheetCount = Timesheet::query()->count();
            $amendmentCount = HrTimeEntryAmendment::query()->count();

            $this->actingAs($lead)->post($uri, $payload)->assertNotFound();

            expect(HrTimeEntry::query()->count())->toBe($entryCount)
                ->and(Timesheet::query()->count())->toBe($timesheetCount)
                ->and(HrTimeEntryAmendment::query()->count())->toBe($amendmentCount);
        }
    }
});

test('approve-only direct-report commands derive canonical shift ownership and reject mismatches', function () {
    $localSite = Site::factory()->create(['name' => 'Canonical command Site']);
    $foreignSite = Site::factory()->create(['name' => 'Conflicting command Site']);
    $lead = hrRoleUser('team_lead');
    $report = hrRoleUser('support_worker');
    hrTimeProfile($lead, site: $localSite);
    hrTimeProfile($report, $lead, $localSite);
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');
    denyHrTimePermission($lead, 'timesheets.manageAny');
    denyHrTimePermission($lead, 'reports.viewAny');
    $localClient = Client::factory()->create(['site_id' => $localSite->id]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $manualShift = Shift::factory()->create([
        'user_id' => $report->id,
        'client_id' => $localClient->id,
        'site_id' => $localSite->id,
    ]);
    $behalfShift = Shift::factory()->create([
        'user_id' => $report->id,
        'client_id' => $localClient->id,
        'site_id' => $localSite->id,
    ]);
    $mismatchShift = Shift::factory()->create([
        'user_id' => $report->id,
        'client_id' => $localClient->id,
        'site_id' => $localSite->id,
    ]);

    $this->actingAs($lead)
        ->post('/hr/time/entries', [
            'user_id' => $report->id,
            'shift_id' => $manualShift->id,
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 17:00',
        ])
        ->assertSessionHasNoErrors();
    $manualEntry = HrTimeEntry::query()->where('shift_id', $manualShift->id)->sole();
    expect((int) $manualEntry->client_id)->toBe($localClient->id)
        ->and((int) $manualEntry->site_id)->toBe($localSite->id);

    $this->actingAs($lead)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $report->id,
            'shift_id' => $behalfShift->id,
            'clock_in' => '2026-04-21 09:00',
            'reason' => 'Canonical local direct-report clock.',
        ])
        ->assertSessionHasNoErrors();
    $behalfEntry = HrTimeEntry::query()->where('shift_id', $behalfShift->id)->sole();
    expect((int) $behalfEntry->client_id)->toBe($localClient->id)
        ->and((int) $behalfEntry->site_id)->toBe($localSite->id);

    $before = HrTimeEntry::query()->count();
    $this->actingAs($lead)
        ->post('/hr/time/entries', [
            'user_id' => $report->id,
            'shift_id' => $mismatchShift->id,
            'client_id' => $foreignClient->id,
            'clock_in' => '2026-04-22 09:00',
            'clock_out' => '2026-04-22 17:00',
        ])
        ->assertNotFound();
    $this->actingAs($lead)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $report->id,
            'shift_id' => $mismatchShift->id,
            'site_id' => $foreignSite->id,
            'clock_in' => '2026-04-22 09:00',
            'reason' => 'Conflicting Site must be concealed.',
        ])
        ->assertNotFound();
    expect(HrTimeEntry::query()->count())->toBe($before);
});

test('reports viewAny never expands Site authority for time-entry mutations', function () {
    $localSite = Site::factory()->create(['name' => 'Manage local Site']);
    $foreignSite = Site::factory()->create(['name' => 'Manage foreign Site']);
    $localManager = hrRoleUser('hr');
    $globalManager = hrRoleUser('hr');
    $foreignStaff = hrRoleUser('support_worker');
    hrTimeProfile($localManager, site: $localSite);
    hrTimeProfile($globalManager, site: $localSite);
    hrTimeProfile($foreignStaff, site: $foreignSite);
    grantHrTimePermission($localManager, 'timesheets.manageAny');
    denyHrTimePermission($localManager, 'reports.viewAny');
    grantHrTimePermission($globalManager, 'timesheets.viewAny');
    grantHrTimePermission($globalManager, 'timesheets.manageAny');
    grantHrTimePermission($globalManager, 'reports.viewAny');
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $deniedShift = Shift::factory()->create([
        'user_id' => $foreignStaff->id,
        'client_id' => $foreignClient->id,
        'site_id' => $foreignSite->id,
    ]);
    $globalManualShift = Shift::factory()->create([
        'user_id' => $foreignStaff->id,
        'client_id' => $foreignClient->id,
        'site_id' => $foreignSite->id,
    ]);
    $globalClockShift = Shift::factory()->create([
        'user_id' => $foreignStaff->id,
        'client_id' => $foreignClient->id,
        'site_id' => $foreignSite->id,
    ]);

    $this->actingAs($localManager)
        ->post('/hr/time/entries', [
            'user_id' => $foreignStaff->id,
            'shift_id' => $deniedShift->id,
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 17:00',
        ])
        ->assertNotFound();
    $this->actingAs($localManager)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $foreignStaff->id,
            'shift_id' => $deniedShift->id,
            'clock_in' => '2026-04-20 09:00',
            'reason' => 'Foreign Site must remain concealed.',
        ])
        ->assertNotFound();

    $this->actingAs($globalManager)
        ->post('/hr/time/entries', [
            'user_id' => $foreignStaff->id,
            'shift_id' => $globalManualShift->id,
            'clock_in' => '2026-04-21 09:00',
            'clock_out' => '2026-04-21 17:00',
        ])
        ->assertNotFound();
    $this->actingAs($globalManager)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $foreignStaff->id,
            'shift_id' => $globalClockShift->id,
            'clock_in' => '2026-04-22 09:00',
            'reason' => 'Explicit all-Site attendance authority.',
        ])
        ->assertNotFound();

    expect(HrTimeEntry::query()->where('shift_id', $deniedShift->id)->exists())->toBeFalse();
    expect(HrTimeEntry::query()->whereIn('shift_id', [$globalManualShift->id, $globalClockShift->id])->exists())
        ->toBeFalse();
});

test('voiding an entry soft-deletes it with a required reason', function () {
    $site = Site::factory()->create();
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager, site: $site);
    hrTimeProfile($staff, $manager, $site);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $entry = hrScopedTimeEntry($staff, [
        'status' => 'submitted',
    ]);

    // Reason required.
    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [])
        ->assertSessionHasErrors(['reason']);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [
            'reason' => str_repeat('x', 256),
        ])
        ->assertSessionHasErrors(['reason']);
    expect(HrTimeEntry::find($entry->id))->not->toBeNull();

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [
            'reason' => 'Duplicate entry created in error.',
        ])
        ->assertSessionHasNoErrors();

    expect(HrTimeEntry::withTrashed()->find($entry->id)->trashed())->toBeTrue();
    expect($entry->amendments()->where('field_name', 'voided')->exists())->toBeTrue();
});

test('approved entries cannot be voided', function () {
    $site = Site::factory()->create();
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager, site: $site);
    hrTimeProfile($staff, $manager, $site);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $entry = hrScopedTimeEntry($staff, [
        'status' => 'approved',
    ]);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [
            'reason' => 'Trying to void an approved entry.',
        ])
        ->assertSessionHas('error');

    expect(HrTimeEntry::find($entry->id))->not->toBeNull();
});

test('an approve-only manager receives concealed not found for an entry outside their team', function () {
    // team_lead has timesheets.approve but NOT manageAny (RbacSeeder) — they may
    // only touch their own or their direct reports' entries.
    $lead = hrRoleUser('team_lead');
    $stranger = hrRoleUser('support_worker');
    hrTimeProfile($lead);
    hrTimeProfile($stranger); // NOT managed by $lead
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');

    $entry = hrScopedTimeEntry($stranger, [
        'status' => 'submitted',
    ]);

    $this->actingAs($lead)
        ->put("/hr/time/entries/{$entry->id}", [
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 16:00',
            'break_minutes' => 30,
            'amendment_reason' => 'Trying to amend a stranger entry.',
        ])
        ->assertNotFound();

    // Direct-object concealment runs before payload validation, so malformed
    // input cannot be used as an existence oracle for an inaccessible ID.
    $this->actingAs($lead)
        ->put("/hr/time/entries/{$entry->id}", [])
        ->assertNotFound();
    $this->actingAs($lead)
        ->put('/hr/time/entries/not-an-id', [])
        ->assertNotFound();

    $this->actingAs($lead)
        ->post("/hr/time/entries/{$entry->id}/correct", [
            'clock_out' => '2026-04-20 17:00',
            'reason' => 'Trying to correct a stranger entry.',
        ])
        ->assertNotFound();
});

test('an approve-only manager can amend their direct report\'s entry', function () {
    $lead = hrRoleUser('team_lead');
    $report = hrRoleUser('support_worker');
    hrTimeProfile($lead);
    hrTimeProfile($report, $lead); // managed by $lead
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');

    $entry = hrScopedTimeEntry($report, [
        'status' => 'submitted',
    ]);

    $this->actingAs($lead)
        ->put("/hr/time/entries/{$entry->id}", [
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 16:00',
            'break_minutes' => 30,
            'amendment_reason' => 'Corrected the recorded finish time.',
        ])
        ->assertSessionHasNoErrors();
});

test('correcting an active missed clock-out submits it and records the reason', function () {
    $site = Site::factory()->create();
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager, site: $site);
    hrTimeProfile($staff, $manager, $site);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $clockIn = CarbonImmutable::parse('2026-04-20 09:00:00', config('app.worker_timezone'))->utc();
    $entry = hrScopedTimeEntry($staff, [
        'entry_date' => '2026-04-20',
        'clock_in' => $clockIn,
        'clock_out' => null,
        'total_hours' => null,
        'status' => 'active',
        'entry_type' => 'clock',
    ]);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/correct", [
            'clock_out' => '2026-04-20 17:00',
            'break_minutes' => 30,
            'reason' => 'Confirmed finish time with the on-call supervisor.',
        ])
        ->assertSessionHasNoErrors();

    $entry->refresh();
    expect($entry->clock_out)->not->toBeNull();
    expect($entry->status)->toBe('submitted');
    expect((float) $entry->total_hours)->toBeGreaterThan(0);
    expect($entry->amendments()->where('field_name', 'clock_out')->exists())->toBeTrue();
});

test('a manager can add a team-visible note recorded on the amendment trail', function () {
    $site = Site::factory()->create();
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager, site: $site);
    hrTimeProfile($staff, $manager, $site);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $entry = hrScopedTimeEntry($staff, [
        'status' => 'submitted',
    ]);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/note", [
            'note' => 'Confirmed the extra hour with the duty manager.',
        ])
        ->assertSessionHasNoErrors();

    expect(
        $entry->amendments()
            ->where('field_name', 'note')
            ->where('reason', 'Confirmed the extra hour with the duty manager.')
            ->exists()
    )->toBeTrue();
});

test('a manual sleepover entry persists the disturbance log', function () {
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager);
    hrTimeProfile($staff, $manager);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');
    $client = Client::factory()->create([
        'site_id' => $manager->hrEmployeeProfile->primary_site_id,
        'status' => 'active',
    ]);

    $this->actingAs($manager)
        ->post('/hr/time/entries', [
            'user_id' => $staff->id,
            'clock_in' => '2026-04-20 22:00',
            'clock_out' => '2026-04-21 06:00',
            'client_id' => $client->id,
            'break_minutes' => 0,
            'pay_type' => 'sleepover',
            'is_sleepover' => true,
            'sleepover_disturbances' => [
                ['start' => '01:00', 'end' => '01:30', 'minutes' => 30],
                ['start' => '03:15', 'end' => '03:45', 'minutes' => 30],
            ],
        ])
        ->assertSessionHasNoErrors();

    $entry = HrTimeEntry::query()
        ->where('user_id', $staff->id)
        ->where('is_sleepover', true)
        ->firstOrFail();

    expect($entry->sleepover_disturbances)->toHaveCount(2);
    expect((int) $entry->sleepover_disturbances[0]['minutes'])->toBe(30);
});

test('syncEntryFromSession backfills a closed session that has no time entry', function () {
    // Mirrors the §5 backfill migration: a legacy closed session with no
    // HrTimeEntry should get one created (submitted, hours computed) so it
    // surfaces in the Time Entries tab + KPIs.
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $site = Site::factory()->create();
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);

    $attendance = app(AttendanceService::class);
    $session = $attendance->clockIn($staff);
    $this->travel(3)->hours();
    $session = $attendance->clockOut($staff, $session, ['break_minutes' => 30]);

    // Simulate a genuine legacy gap. A current clock-out creates and links both
    // rows, so clear that synthetic link before removing the entry; retaining a
    // pointer to a missing entry is contradictory provenance and must fail closed.
    Timesheet::query()
        ->where('attendance_session_id', $session->id)
        ->update(['hr_time_entry_id' => null]);
    HrTimeEntry::query()
        ->where('attendance_session_id', $session->id)
        ->forceDelete();
    expect(
        HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists()
    )->toBeFalse();

    app(TimeTrackingService::class)
        ->syncEntryFromSession($session->fresh(), $staff);

    $entry = HrTimeEntry::query()
        ->where('attendance_session_id', $session->id)
        ->firstOrFail();

    expect($entry->status)->toBe('submitted');
    expect($entry->clock_out)->not->toBeNull();
    expect((float) $entry->total_hours)->toBeGreaterThan(2.0);
    expect((int) $entry->site_id)->toBe($site->id);
    expect((int) Timesheet::query()
        ->where('attendance_session_id', $session->id)
        ->value('hr_time_entry_id'))->toBe($entry->id);
});

test('attendance projection uses the worker local work date for entries and payroll locks', function () {
    $site = Site::factory()->create();
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    $localClockIn = CarbonImmutable::parse(
        '2026-04-20 00:30:00',
        config('app.worker_timezone', 'Pacific/Auckland'),
    );

    HrPayrollRun::factory()->create([
        'period_start' => '2026-04-19',
        'period_end' => '2026-04-19',
        'status' => 'locked',
        'locked_at' => now(),
        'locked_by' => $staff->id,
        'created_by' => $staff->id,
    ]);

    $session = app(AttendanceService::class)->clockIn($staff, [
        'clock_in_at' => $localClockIn->utc(),
        'source' => 'test',
    ]);
    $entry = HrTimeEntry::query()->where('attendance_session_id', $session->id)->sole();
    expect($entry->entry_date?->toDateString())->toBe('2026-04-20');

    $blockedStaff = hrRoleUser('support_worker');
    hrTimeProfile($blockedStaff);
    $blockedStaff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    HrPayrollRun::factory()->create([
        'period_start' => '2026-04-20',
        'period_end' => '2026-04-20',
        'status' => 'locked',
        'locked_at' => now(),
        'locked_by' => $staff->id,
        'created_by' => $staff->id,
    ]);

    expect(fn () => app(AttendanceService::class)->clockIn($blockedStaff, [
        'clock_in_at' => $localClockIn->utc(),
        'source' => 'test',
    ]))->toThrow(LogicException::class, 'locked payroll period');
    expect(HrAttendanceSession::query()->where('user_id', $blockedStaff->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('user_id', $blockedStaff->id)->exists())->toBeFalse();
});

test('my hr clock out recovers only a genuinely orphaned active entry', function () {
    $site = Site::factory()->create();
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    $clockIn = now()->subHours(2);
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'site_id' => $site->id,
        'entry_date' => $clockIn->copy()
            ->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString(),
        'clock_in' => $clockIn,
        'clock_out' => null,
        'entry_type' => 'clock',
        'status' => 'active',
        'created_by' => $staff->id,
    ]);

    $this->actingAs($staff)
        ->post('/hr/my/time/clock-out', ['break_minutes' => 10])
        ->assertSessionHas('success');

    $entry->refresh();
    expect($entry->clock_out)->not->toBeNull()
        ->and($entry->status)->toBe('submitted');
});

test('my hr can recover a genuine orphan without reopening its cancelled Shift', function () {
    $site = Site::factory()->create();
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $staff->id,
        'status' => 'cancelled',
    ]);
    $clockIn = now()->subHours(2);
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'attendance_session_id' => null,
        'shift_id' => $shift->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'entry_date' => $clockIn->copy()
            ->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString(),
        'clock_in' => $clockIn,
        'clock_out' => null,
        'entry_type' => 'clock',
        'status' => 'active',
        'created_by' => $staff->id,
    ]);

    $this->actingAs($staff)
        ->post('/hr/my/time/clock-out', ['break_minutes' => 10])
        ->assertSessionHas('success');

    $entry->refresh();
    $shift->refresh();
    expect($entry->clock_out)->not->toBeNull()
        ->and($entry->status)->toBe('submitted')
        ->and($shift->status)->toBe('cancelled')
        ->and(HrAttendanceSession::query()->where('shift_id', $shift->id)->exists())->toBeFalse();
});

test('my hr orphan recovery leaves payroll linked entries byte identical', function () {
    $site = Site::factory()->create();
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    $clockIn = now()->subHours(2);
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'site_id' => $site->id,
        'entry_date' => $clockIn->copy()
            ->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString(),
        'clock_in' => $clockIn,
        'clock_out' => null,
        'entry_type' => 'clock',
        'status' => 'active',
        'created_by' => $staff->id,
    ]);
    $timesheet = Timesheet::query()->create([
        'user_id' => $staff->id,
        'hr_time_entry_id' => $entry->id,
        'site_id' => $site->id,
        'activity_type' => 'other',
        'work_date' => $entry->entry_date,
        'starts_at' => $entry->clock_in,
        'ends_at' => now(),
        'break_minutes' => 0,
        'status' => 'draft',
        'created_by' => $staff->id,
    ]);
    $timesheet->forceFill(['payroll_reference' => 'hr-payroll-run:orphan-lock'])->saveQuietly();
    $entryBefore = $entry->fresh()->getAttributes();
    $timesheetBefore = $timesheet->fresh()->getAttributes();

    $this->actingAs($staff)
        ->post('/hr/my/time/clock-out', ['break_minutes' => 10])
        ->assertSessionHas('error');

    expect($entry->fresh()->getAttributes())->toBe($entryBefore)
        ->and($timesheet->fresh()->getAttributes())->toBe($timesheetBefore);
});

test('my hr does not close a linked entry after attendance projection provenance fails', function () {
    $site = Site::factory()->create();
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    $session = HrAttendanceSession::query()->create([
        'user_id' => $staff->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'legacy',
        'created_by' => $staff->id,
    ]);
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'attendance_session_id' => $session->id,
        'shift_id' => null,
        'site_id' => $site->id,
        'entry_date' => $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'clock_in' => $session->clock_in_at->copy()->subMinute(),
        'clock_out' => null,
        'entry_type' => 'clock',
        'status' => 'active',
        'source_type' => 'attendance',
        'source_id' => $session->id,
        'created_by' => $staff->id,
    ]);

    $this->actingAs($staff)
        ->post('/hr/my/time/clock-out')
        ->assertSessionHas('error');

    $session->refresh();
    $entry->refresh();
    expect($session->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($entry->clock_out)->toBeNull()
        ->and($entry->status)->toBe('active');
});

test('my hr does not move attendance evidence across worker local work dates', function () {
    $site = Site::factory()->create();
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($staff);
    $staff->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
    $session = HrAttendanceSession::query()->create([
        'user_id' => $staff->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'legacy',
        'created_by' => $staff->id,
    ]);
    $canonicalWorkDate = $session->clock_in_at->copy()
        ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
        ->toDateString();
    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'attendance_session_id' => $session->id,
        'shift_id' => null,
        'site_id' => $site->id,
        'entry_date' => CarbonImmutable::parse($canonicalWorkDate)->subDay()->toDateString(),
        'clock_in' => $session->clock_in_at,
        'clock_out' => null,
        'entry_type' => 'clock',
        'status' => 'active',
        'source_type' => 'attendance',
        'source_id' => $session->id,
        'created_by' => $staff->id,
    ]);
    $sessionBefore = $session->fresh()->getAttributes();
    $entryBefore = $entry->fresh()->getAttributes();

    $this->actingAs($staff)
        ->post('/hr/my/time/clock-out')
        ->assertSessionHas('error');

    expect($session->fresh()->getAttributes())->toBe($sessionBefore)
        ->and($entry->fresh()->getAttributes())->toBe($entryBefore)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
});

test('hr clock out rejects break_minutes above the shared 240 cap', function () {
    // D4 — break cap unified to 240 across the HR module too, matching the
    // frontline /attendance + /timesheets surfaces (this path was 480 before).
    $user = hrRoleUser('support_worker');
    hrTimeProfile($user);
    grantHrTimePermission($user, 'timesheets.viewAny');

    $this->actingAs($user)
        ->post('/hr/time/clock-out', [
            'break_minutes' => 300,
        ])
        ->assertSessionHasErrors(['break_minutes']);
});
