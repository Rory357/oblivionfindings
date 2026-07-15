<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Site $siteA;

    protected Site $siteB;

    protected Client $clientA;

    protected Client $clientB;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-06 10:00:00'));

        $this->siteA = Site::factory()->create(['name' => 'Harbour House']);
        $this->siteB = Site::factory()->create(['name' => 'Forest House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->clientA = Client::factory()->create([
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $this->clientB = Client::factory()->create([
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
    }

    public function test_frontline_shift_index_redirects_to_my_day(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned']);

        $this->actingAs($user)
            ->get('/operations/shifts?from=2026-04-06&to=2026-04-06')
            ->assertRedirect(route('my-day'));
    }

    public function test_user_cannot_access_shift_from_another_site(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned']);

        $foreignShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $this->actingAs($user)
            ->get("/operations/shifts/{$foreignShift->id}")
            ->assertForbidden();
    }

    public function test_shift_manage_any_without_reports_permission_remains_site_scoped(): void
    {
        $scheduler = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAny', 'shifts.manageAny']);

        $visibleShift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $scheduler->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $hiddenShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $scheduler->id,
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $this->actingAs($scheduler)
            ->get('/operations/shifts?from=2026-04-06&to=2026-04-06')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/shifts/index')
                ->has('shifts.data', 1)
                ->where('shifts.data.0.id', $visibleShift->id)
            );

        $this->actingAs($scheduler)
            ->get("/operations/shifts/{$hiddenShift->id}")
            ->assertForbidden();
    }

    public function test_user_cannot_approve_timesheet_from_another_site(): void
    {
        $approver = $this->makeSiteScopedUser([$this->siteA], ['timesheets.approve']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->copy()->subDay()->setTime(9, 0),
            'ends_at' => now()->copy()->subDay()->setTime(17, 0),
            'actual_starts_at' => now()->copy()->subDay()->setTime(9, 0),
            'actual_ends_at' => now()->copy()->subDay()->setTime(17, 0),
            'status' => 'completed',
        ]);

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->siteB->id,
            'clock_in_at' => Carbon::parse('2026-04-05 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-04-05 17:00:00'),
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        $timesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'user_id' => $staff->id,
            'client_id' => $this->clientB->id,
            'shift_site_id' => $this->siteB->id,
            'work_date' => '2026-04-05',
            'starts_at' => Carbon::parse('2026-04-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-05 17:00:00'),
            'break_minutes' => 0,
        ]);

        $this->actingAs($approver)
            ->post(route('operations.timesheets.approve', $timesheet))
            ->assertForbidden();
    }

    public function test_timesheet_manage_any_without_reports_permission_remains_site_scoped(): void
    {
        $manager = $this->makeSiteScopedUser([$this->siteA], ['timesheets.viewAny', 'timesheets.manageAny']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        $visibleTimesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $staff->id,
            'client_id' => $this->clientA->id,
            'shift_id' => null,
            'shift_site_id' => $this->siteA->id,
            'work_date' => '2026-04-05',
        ]);

        $hiddenTimesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $staff->id,
            'client_id' => $this->clientB->id,
            'shift_id' => null,
            'shift_site_id' => $this->siteB->id,
            'work_date' => '2026-04-05',
        ]);

        $this->actingAs($manager)
            ->get(route('operations.timesheets.index', ['mode' => 'approvals']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/timesheets/index')
                ->has('timesheets.data', 1)
                ->where('timesheets.data.0.id', $visibleTimesheet->id)
            );

        $this->actingAs($manager)
            ->get(route('operations.timesheets.index', [
                'mode' => 'approvals',
                'edit' => $hiddenTimesheet->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('timesheets.data', fn ($rows) => collect($rows)
                    ->doesntContain(fn ($row) => (int) $row['id'] === (int) $hiddenTimesheet->id))
            );
    }

    public function test_handover_acknowledgement_is_blocked_for_foreign_site(): void
    {
        $incomingUser = $this->makeSiteScopedUser([$this->siteA], ['shifts.update', 'shifts.viewAssigned']);
        $outgoingUser = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $outgoingUser->id,
            'starts_at' => now()->copy()->subHours(4),
            'ends_at' => now()->copy()->addHour(),
            'status' => 'in_progress',
        ]);

        $incomingShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $incomingUser->id,
            'starts_at' => now()->copy()->addMinutes(30),
            'ends_at' => now()->copy()->addHours(4),
            'status' => 'scheduled',
        ]);

        $handover = ShiftHandover::factory()->create([
            'organization_id' => $incomingUser->organization_id,
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->clientB->id,
            'outgoing_staff_id' => $outgoingUser->id,
            'incoming_staff_id' => $incomingUser->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(15),
            'submitted_by' => $outgoingUser->id,
        ]);

        $this->actingAs($incomingUser)
            ->patch("/operations/handovers/{$handover->id}/acknowledge")
            ->assertForbidden();
    }

    public function test_reporting_only_includes_accessible_site_data(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['operations.reports.view']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker', 'name' => 'Scoped Staff']);

        Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)
            ->get('/operations/reports/shifts?date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/reports/Shifts')
            ->has('sites', 1)
            ->where('sites.0.id', $this->siteA->id)
            ->where('report.staff_utilisation.total_shifts', 1)
        );
    }

    public function test_shift_manage_any_without_reports_permission_does_not_bypass_operations_report_scope(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['operations.reports.view', 'shifts.manageAny']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker', 'name' => 'Scoped Staff']);

        Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        $this->actingAs($user)
            ->get('/operations/reports/shifts?date_from=2026-04-01&date_to=2026-04-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/reports/Shifts')
                ->has('sites', 1)
                ->where('sites.0.id', $this->siteA->id)
                ->where('report.staff_utilisation.total_shifts', 1)
            );
    }

    public function test_csv_export_respects_site_scope(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['operations.reports.view']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        Timesheet::factory()->create([
            'user_id' => $staff->id,
            'client_id' => $this->clientA->id,
            'shift_id' => null,
            'shift_site_id' => $this->siteA->id,
            'work_date' => '2026-04-05',
            'reconciliation_status' => 'blocked',
            'reconciliation_summary' => 'Site A mismatch.',
        ]);

        Timesheet::factory()->create([
            'user_id' => $staff->id,
            'client_id' => $this->clientB->id,
            'shift_id' => null,
            'shift_site_id' => $this->siteB->id,
            'work_date' => '2026-04-05',
            'reconciliation_status' => 'blocked',
            'reconciliation_summary' => 'Site B mismatch.',
        ]);

        $response = $this->actingAs($user)
            ->get('/operations/reports/shifts/export?dataset=reconciliation&date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($this->siteA->name, $content);
        $this->assertStringNotContainsString($this->siteB->name, $content);
    }

    public function test_elevated_user_can_bypass_site_scope(): void
    {
        $admin = $this->makeBypassUser([
            'shifts.viewAny',
            'shifts.manageAny',
            'timesheets.manageAny',
            'reports.viewAny',
            'controlRoom.viewAny',
        ]);

        // Index uses worker-timezone (Pacific/Auckland) day boundaries, so create
        // both shifts inside the same NZ day for the 2026-04-06 filter. Convert
        // to UTC explicitly so Eloquent stores the UTC instant (the cast format
        // does not auto-convert from a non-UTC Carbon).
        $tz = 'Pacific/Auckland';

        $shiftA = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => Carbon::parse('2026-04-06 09:00', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-04-06 13:00', $tz)->utc(),
            'status' => 'scheduled',
        ]);

        $shiftB = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => Carbon::parse('2026-04-06 14:00', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-04-06 18:00', $tz)->utc(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($admin)
            ->get('/operations/shifts?from=2026-04-06&to=2026-04-06')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('shifts.data', 2));

        $this->actingAs($admin)
            ->get("/operations/shifts/{$shiftB->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('shift.id', $shiftB->id));

        $this->assertNotSame($shiftA->id, $shiftB->id);
    }

    public function test_control_room_shift_alert_context_is_filtered_by_site(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['controlRoom.viewAny']);

        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'site_id' => $this->siteB->id,
            'client_id' => $this->clientB->id,
            'context' => [
                'shift_context' => [
                    'shift' => ['id' => 901],
                    'site' => ['id' => $this->siteB->id, 'name' => $this->siteB->name],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get("/control-room/alerts/{$alert->id}")
            ->assertForbidden();
    }

    public function test_shift_editable_payload_does_not_leak_other_site_clients(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.update']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)->getJson("/operations/shifts/{$shift->id}/editable");

        $response->assertOk()
            ->assertJsonPath('id', $shift->id)
            ->assertJsonPath('client.id', $this->clientA->id)
            ->assertJsonMissing(['id' => $this->clientB->id]);
    }

    public function test_shift_show_scopes_medication_witnesses_to_accessible_sites(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned', 'medications.administer.record']);
        $visibleWitness = $this->makeSiteScopedUser([$this->siteA], ['medications.controlled.witness']);
        $hiddenWitness = $this->makeSiteScopedUser([$this->siteB], ['medications.controlled.witness']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)->get("/operations/shifts/{$shift->id}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/show')
            ->has('medicationWitnesses', 1)
            ->where('medicationWitnesses.0.id', $visibleWitness->id)
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->missing('medicationWitnesses.1')
        );

        $this->assertNotSame($visibleWitness->id, $hiddenWitness->id);
    }

    public function test_shift_assignment_candidates_exclude_hidden_site_staff_for_site_bound_scheduler(): void
    {
        $scheduler = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAny', 'shifts.manageAny']);
        $visibleCandidate = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned']);
        $hiddenCandidate = $this->makeSiteScopedUser([$this->siteB], ['shifts.viewAssigned']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $visibleCandidate->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($scheduler)->get("/operations/shifts/{$shift->id}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/show')
            ->has('assignmentCandidates')
        );

        $candidateIds = collect($response->viewData('page')['props']['assignmentCandidates'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($visibleCandidate->id, $candidateIds);
        $this->assertNotContains($hiddenCandidate->id, $candidateIds);
    }

    public function test_shift_assign_rejects_hidden_site_staff_for_site_bound_scheduler(): void
    {
        $scheduler = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAny', 'shifts.manageAny']);
        $visibleStaff = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned']);
        $hiddenStaff = $this->makeSiteScopedUser([$this->siteB], ['shifts.viewAssigned']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $visibleStaff->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $this->actingAs($scheduler)
            ->post(route('operations.shifts.assign', $shift), [
                'user_id' => $hiddenStaff->id,
            ])
            ->assertForbidden();

        $shift->refresh();
        $this->assertSame($visibleStaff->id, $shift->user_id);
    }

    public function test_shift_store_rejects_hidden_site_staff_for_site_bound_scheduler(): void
    {
        $scheduler = $this->makeSiteScopedUser([$this->siteA], ['shifts.create']);
        $hiddenStaff = $this->makeSiteScopedUser([$this->siteB], ['shifts.viewAssigned']);

        $this->actingAs($scheduler)
            ->post(route('operations.shifts.store'), [
                'client_id' => $this->clientA->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $hiddenStaff->id,
                'starts_at' => now()->copy()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->copy()->addDay()->setTime(13, 0)->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('shifts', [
            'client_id' => $this->clientA->id,
            'user_id' => $hiddenStaff->id,
        ]);
    }

    /**
     * @param  array<int, Site>  $sites
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeSiteScopedUser(array $sites, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-SITE-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $sites[0]->id ?? null,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeBypassUser(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'admin',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionIds = collect($permissionKeys)
            ->map(function (string $key) {
                $module = str($key)->before('.')->value() ?: 'operations';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'description' => $key,
                        'group' => $module,
                        'module' => $module,
                    ],
                )->id;
            })
            ->all();

        $permissionMap = collect($permissionIds)
            ->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    // ──────────────────────────────────────────────
    // ShiftReportsController site isolation removed
    // ──────────────────────────────────────────────
    //
    // The legacy `/reports/shifts` direct controller was removed; the URL is
    // now a 301 redirect to `/operations/reports/shifts`. Site-scoped report
    // coverage now lives in `tests/Feature/Operations/ShiftReportControllerTest.php`
    // against the canonical surface, including the legacy redirect assertion.
}
