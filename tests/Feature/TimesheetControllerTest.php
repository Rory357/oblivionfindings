<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimesheetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected User $otherStaff;

    protected User $finance;

    protected User $hrReviewer;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));

        $this->site = Site::factory()->create([
            'name' => 'Matai House',
            'type' => 'house',
        ]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);

        $this->admin = $this->makeRoleUser('admin');
        $this->staff = $this->makeRoleUser('support_worker');
        $this->otherStaff = $this->makeRoleUser('support_worker');
        $this->finance = $this->makeRoleUser('finance');
        $this->hrReviewer = $this->makeRoleUser('hr');

        foreach ([$this->admin, $this->staff, $this->otherStaff, $this->finance, $this->hrReviewer] as $user) {
            $this->createEmployeeProfile($user);
        }

        $this->grantPermissions($this->staff, [
            'timesheets.viewAssigned',
            'timesheets.create',
            'timesheets.update',
            'timesheets.submit',
        ]);
    }

    public function test_authentication_is_required_for_operations_timesheet_routes(): void
    {
        $this->get(route('operations.timesheets.index'))->assertRedirect('/login');
        $this->get(route('operations.timesheets.create'))->assertRedirect('/login');
        $this->get(route('operations.timesheets.approvals'))->assertRedirect('/login');
    }

    public function test_admin_index_renders_current_operations_timesheet_page(): void
    {
        $this->makeDraftTimesheet($this->staff);
        $this->makeDraftTimesheet($this->otherStaff);

        $response = $this->actingAs($this->admin)
            ->get(route('operations.timesheets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/timesheets/index')
                ->has('timesheets.data', 2)
                ->where('canApprove', true)
                ->where('canCreate', true)
                ->has('clients', 1)
                ->has('staff')
            );
    }

    public function test_staff_index_is_limited_to_own_timesheets(): void
    {
        $ownTimesheet = $this->makeDraftTimesheet($this->staff);
        $this->makeDraftTimesheet($this->otherStaff);

        $this->actingAs($this->staff)
            ->get(route('operations.timesheets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/timesheets/index')
                ->has('timesheets.data', 1)
                ->where('timesheets.data.0.id', $ownTimesheet->id)
                ->where('canApprove', false)
                ->where('canCreate', true)
            );
    }

    public function test_non_approvers_cannot_access_the_approval_queue(): void
    {
        // Frontline support_worker is bounced away from approval-only pages
        // by the `role_scope:my-day` middleware (added when /my-day became
        // the canonical staff home), so a 302 redirect to /my-day replaces
        // the older 403. Either way the worker never reaches the approvals
        // queue — the assertion just had to follow the new convention.
        $this->actingAs($this->staff)
            ->get(route('operations.timesheets.approvals'))
            ->assertRedirect('/my-day');
    }

    public function test_legacy_create_route_redirects_into_unified_dialog(): void
    {
        // The standalone /create page was retired in the redesign — every
        // create flow now funnels through the CreateTimesheetDialog on the
        // index page. The legacy GET route is preserved as a redirect so old
        // links + shift-detail "Create timesheet" buttons keep working.
        $this->actingAs($this->staff)
            ->get(route('operations.timesheets.create'))
            ->assertRedirect('/operations/timesheets?create=1');
    }

    public function test_staff_can_store_draft_timesheet_for_their_own_shift(): void
    {
        $shift = $this->makeScheduledShift($this->staff);

        $response = $this->actingAs($this->staff)
            ->post(route('operations.timesheets.store'), $this->validStorePayload($shift));

        $timesheet = Timesheet::query()->latest('id')->firstOrFail();

        // The unified store now redirects back to the index page (with the
        // new timesheet auto-opened in the View dialog) instead of the old
        // per-row edit page. The DB write contract is unchanged.
        $response->assertRedirect(route('operations.timesheets.index', ['view' => $timesheet->id]));
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'created_by' => $this->staff->id,
            'break_minutes' => 30,
        ]);
    }

    public function test_duplicate_draft_creation_via_canonical_json_route_returns_shift_id_error(): void
    {
        $shift = $this->makeScheduledShift($this->staff);
        $this->makeDraftTimesheet($this->staff, ['shift' => $shift]);

        $this->actingAs($this->staff)
            ->postJson(route('operations.timesheets.store'), $this->validStorePayload($shift))
            ->assertUnprocessable()
            ->assertJsonPath('errors.shift_id.0', 'A timesheet already exists for this shift and staff member.');

        $this->assertSame(1, Timesheet::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $this->staff->id)
            ->count());
    }

    public function test_staff_cannot_store_timesheet_for_another_users_shift(): void
    {
        $shift = $this->makeScheduledShift($this->otherStaff);

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.store'), $this->validStorePayload($shift))
            ->assertForbidden();
    }

    public function test_manual_timesheets_can_be_created_without_a_shift(): void
    {
        // Manual mode now requires `mode=manual` + an activity_type. Linked
        // client/site are optional. The test still locks in the original
        // intent: a worker can log non-shift time that lands as `draft`.
        $response = $this->actingAs($this->staff)
            ->post(route('operations.timesheets.store'), [
                'mode' => 'manual',
                'activity_type' => 'travel',
                'activity_items' => ['Drive Wellington → Karori', 'Stop at Petone'],
                'client_id' => $this->client->id,
                'shift_id' => null,
                'work_date' => '2026-04-11',
                'starts_at' => '2026-04-11 09:00:00',
                'ends_at' => '2026-04-11 17:00:00',
                'break_minutes' => 0,
                'notes' => 'Manual entry for community visit',
            ]);

        $timesheet = Timesheet::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('operations.timesheets.index', ['view' => $timesheet->id]));
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'user_id' => $this->staff->id,
            'shift_id' => null,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'activity_type' => 'travel',
        ]);
        $this->assertSame(['Drive Wellington → Karori', 'Stop at Petone'], $timesheet->fresh()->activity_items);
    }

    public function test_owner_can_update_a_draft_timesheet(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff, [
            'notes' => 'Old note',
            'break_minutes' => 30,
        ]);

        $this->actingAs($this->staff)
            ->put(route('operations.timesheets.update', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 45,
                'notes' => 'Updated note',
                'is_residential_billable' => true,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Updated note',
            'break_minutes' => 45,
            'is_residential_billable' => true,
        ]);
    }

    public function test_payroll_lock_uses_employee_profile_tenant_for_edit_blocking(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff, [
            'notes' => 'Locked note',
        ]);

        HrPayrollRun::query()->create([
            'tenant_id' => 1,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $this->finance->id,
            'created_by' => $this->finance->id,
        ]);

        $this->actingAs($this->staff)
            ->put(route('operations.timesheets.update', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 45,
                'notes' => 'Should stay blocked by payroll',
            ])
            ->assertSessionHas('error', 'This timesheet is locked by a payroll run and cannot be edited.');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Locked note',
            'break_minutes' => 30,
        ]);
    }

    public function test_submitted_timesheets_cannot_be_updated(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);

        $this->actingAs($this->staff)
            ->put(route('operations.timesheets.update', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 0,
                'notes' => 'Should not save',
            ])
            ->assertSessionHas('error', 'Only draft or returned timesheets can be edited.');

        $this->assertDatabaseMissing('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Should not save',
        ]);
    }

    public function test_owner_can_submit_a_draft_timesheet(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff);

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.submit', $timesheet))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'submitted',
            'submitted_by' => $this->staff->id,
        ]);
    }

    public function test_owner_can_save_and_resubmit_a_returned_timesheet_atomically(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff, [
            'status' => 'returned',
            'returned_at' => now()->subHour(),
            'returned_by' => $this->admin->id,
            'returned_notes' => 'Please add the mileage.',
            'break_minutes' => 30,
            'mileage_km' => null,
            'notes' => 'Original note',
        ]);

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.resubmit', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 45,
                'mileage_km' => 12.5,
                'notes' => 'Updated with mileage.',
            ])
            ->assertSessionHas('success');

        $fresh = $timesheet->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame($this->staff->id, $fresh->submitted_by);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertNull($fresh->returned_at);
        $this->assertNull($fresh->returned_by);
        $this->assertNull($fresh->returned_notes);
        $this->assertSame(45, $fresh->break_minutes);
        $this->assertEqualsWithDelta(12.5, (float) $fresh->mileage_km, 0.001);
        $this->assertSame('Updated with mileage.', $fresh->notes);
    }

    public function test_resubmit_endpoint_rejects_invalid_payload_without_changing_status(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->staff, [
            'status' => 'returned',
            'returned_at' => now()->subHour(),
            'returned_by' => $this->admin->id,
            'returned_notes' => 'Please add the mileage.',
            'notes' => 'Original note',
        ]);

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.resubmit', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                // ends_at must be after starts_at — flip them to trigger validation failure
                'starts_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'break_minutes' => 0,
            ])
            ->assertSessionHasErrors('ends_at');

        $fresh = $timesheet->fresh();
        $this->assertSame('returned', $fresh->status);
        $this->assertSame('Original note', $fresh->notes);
        $this->assertNotNull($fresh->returned_at);
    }

    public function test_resubmit_endpoint_blocks_already_submitted_timesheet(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.resubmit', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 0,
            ])
            ->assertSessionHas('error', 'Only draft or returned timesheets can be resubmitted.');

        $this->assertSame('submitted', $timesheet->fresh()->status);
    }

    public function test_resubmit_after_manager_approval_preserves_approved_state_and_reports_stale_action(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff, [
            'notes' => 'Submitted before approval.',
        ]);

        $timesheet->forceFill([
            'status' => 'approved',
            'approved_by' => $this->finance->id,
            'approved_at' => now(),
            'decision_notes' => 'Manager approved first.',
        ])->saveQuietly();

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.resubmit', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 15,
                'notes' => 'Worker stale resubmit after approval.',
            ])
            ->assertSessionHas('error', 'Only draft or returned timesheets can be resubmitted.');

        $fresh = $timesheet->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('Submitted before approval.', $fresh->notes);
        $this->assertSame($this->finance->id, $fresh->approved_by);
    }

    public function test_resubmit_endpoint_requires_ownership(): void
    {
        $timesheet = $this->makeDraftTimesheet($this->otherStaff, [
            'status' => 'returned',
            'returned_at' => now()->subHour(),
            'returned_by' => $this->admin->id,
            'returned_notes' => 'Please add the mileage.',
        ]);

        $this->actingAs($this->staff)
            ->post(route('operations.timesheets.resubmit', $timesheet), [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 0,
            ])
            ->assertForbidden();

        $this->assertSame('returned', $timesheet->fresh()->status);
    }

    public function test_finance_can_approve_a_valid_submitted_timesheet(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);

        $this->actingAs($this->finance)
            ->post(route('operations.timesheets.approve', $timesheet), [
                'decision_notes' => 'Finance approved after reconciliation review.',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'approved',
            'approved_by' => $this->finance->id,
            'decision_notes' => 'Finance approved after reconciliation review.',
        ]);
    }

    public function test_hr_reviewer_can_access_the_approval_queue_and_return_timesheets(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);

        // The legacy /approvals route now redirects into the Pending tab on
        // the unified index. Follow the redirect, then assert the submitted
        // timesheet surfaces on that tab.
        $this->actingAs($this->hrReviewer)
            ->get(route('operations.timesheets.approvals'))
            ->assertRedirect('/operations/timesheets?tab=submitted');

        $this->actingAs($this->hrReviewer)
            ->get(route('operations.timesheets.index', ['tab' => 'submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/timesheets/index')
                ->where('filters.tab', 'submitted')
                ->has('timesheets.data', 1)
                ->where('timesheets.data.0.id', $timesheet->id)
            );

        $this->actingAs($this->hrReviewer)
            ->post(route('operations.timesheets.return', $timesheet), [
                'returned_notes' => 'Please confirm mileage before payroll.',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'returned',
            'returned_by' => $this->hrReviewer->id,
            'returned_notes' => 'Please confirm mileage before payroll.',
        ]);
    }

    public function test_pending_tab_only_returns_submitted_timesheets(): void
    {
        // The old `?mode=approvals` query is replaced by `?tab=submitted` on
        // the unified index — same outcome, different param name. The
        // redesign also retired the `approvalMode` prop; the tab is reflected
        // in `filters.tab` instead.
        $submitted = $this->makeSubmittedTimesheet($this->staff);
        $this->makeDraftTimesheet($this->otherStaff);

        $this->actingAs($this->finance)
            ->get(route('operations.timesheets.index', ['tab' => 'submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/timesheets/index')
                ->where('filters.tab', 'submitted')
                ->has('timesheets.data', 1)
                ->where('timesheets.data.0.id', $submitted->id)
            );
    }

    public function test_bulk_approve_updates_all_selected_valid_timesheets(): void
    {
        $first = $this->makeSubmittedTimesheet($this->staff);
        $second = $this->makeSubmittedTimesheet($this->otherStaff, [
            'work_date' => '2026-04-10',
        ]);

        $this->actingAs($this->finance)
            ->post(route('operations.timesheets.bulkApprove'), [
                'ids' => [$first->id, $second->id],
                'decision_notes' => 'Bulk approved after review.',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', [
            'id' => $first->id,
            'status' => 'approved',
            'approved_by' => $this->finance->id,
        ]);
        $this->assertDatabaseHas('timesheets', [
            'id' => $second->id,
            'status' => 'approved',
            'approved_by' => $this->finance->id,
        ]);
    }

    protected function makeRoleUser(string $roleName): User
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

    protected function createEmployeeProfile(User $user): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-TS-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Operations',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ],
        );
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    protected function makeScheduledShift(User $staff, array $overrides = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-11 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-11 17:00:00'),
            'expected_break_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ], $overrides));
    }

    /**
     * @return array{0: Shift, 1: HrAttendanceSession}
     */
    protected function makeCompletedShiftWithAttendance(User $staff, array $overrides = []): array
    {
        $shift = Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'actual_ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'expected_break_minutes' => 0,
            'status' => 'completed',
            'created_by' => $staff->id,
            'started_by' => $staff->id,
            'completed_by' => $staff->id,
        ], $overrides));

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => $shift->actual_starts_at,
            'clock_out_at' => $shift->actual_ends_at,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        return [$shift, $attendance];
    }

    protected function makeDraftTimesheet(User $staff, array $overrides = []): Timesheet
    {
        $shift = $overrides['shift'] ?? $this->makeScheduledShift($staff, $overrides['shift_overrides'] ?? []);

        return Timesheet::query()->create(array_merge([
            'user_id' => $staff->id,
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'shift_site_id' => $shift->site_id,
            'shift_service_context_id' => $shift->service_context_id,
            'work_date' => $shift->starts_at->toDateString(),
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'break_minutes' => 30,
            'notes' => 'Draft notes',
            'status' => 'draft',
            'created_by' => $staff->id,
            'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => $this->serviceContext->name,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ], collect($overrides)->except(['shift', 'shift_overrides'])->all()));
    }

    protected function makeSubmittedTimesheet(User $staff, array $overrides = []): Timesheet
    {
        [$shift, $attendance] = $this->makeCompletedShiftWithAttendance($staff, $overrides['shift_overrides'] ?? []);

        return Timesheet::query()->create(array_merge([
            'user_id' => $staff->id,
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'shift_site_id' => $shift->site_id,
            'shift_service_context_id' => $shift->service_context_id,
            'work_date' => $shift->starts_at->toDateString(),
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'break_minutes' => 0,
            'notes' => 'Submitted notes',
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
            'submitted_by' => $staff->id,
            'created_by' => $staff->id,
            'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => $this->serviceContext->name,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ], collect($overrides)->except(['shift_overrides'])->all()));
    }

    protected function validStorePayload(Shift $shift): array
    {
        return [
            'mode' => 'shift',
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'work_date' => $shift->starts_at->toDateString(),
            'starts_at' => $shift->starts_at->format('Y-m-d H:i:s'),
            'ends_at' => $shift->ends_at->format('Y-m-d H:i:s'),
            'break_minutes' => 30,
            'notes' => 'Test shift notes',
        ];
    }
}
