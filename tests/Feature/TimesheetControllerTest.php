<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected User $otherStaff;
    protected User $finance;
    protected User $coordinator;
    protected Client $client;
    protected Shift $shift;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        // Admin: has all permissions including manageAny
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // Support worker: limited to own timesheets
        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $supportRole = Role::where('name', 'support_worker')->first();
        $this->staff->roles()->attach($supportRole);

        // Another support worker for ownership tests
        $this->otherStaff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->otherStaff->roles()->attach($supportRole);

        // Finance: can approve timesheets but cannot manageAny
        $this->finance = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        $this->finance->roles()->attach(Role::where('name', 'finance')->first());

        // Coordinator: can approve timesheets, has viewAny
        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        if ($supportRole) {
            $supportRole->permissions()->syncWithoutDetaching(
                \App\Models\Permission::query()
                    ->whereIn('key', ['timesheets.create', 'timesheets.update', 'timesheets.submit'])
                    ->pluck('id')
                    ->all()
            );
        }

        $this->site = Site::factory()->create(['name' => 'Matai House']);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $this->shift = Shift::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

        foreach ([$this->admin, $this->staff, $this->otherStaff, $this->finance, $this->coordinator] as $user) {
            HrEmployeeProfile::query()->create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'employee_number' => 'EMP-TS-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Operations',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Helper to build valid timesheet payload
    // ---------------------------------------------------------------
    private function validTimesheetData(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'work_date' => now()->format('Y-m-d'),
            'starts_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
            'break_minutes' => 30,
            'notes' => 'Test shift notes',
        ], $overrides);
    }

    // ===============================================================
    // 1. AUTHENTICATION REQUIRED FOR ALL ROUTES
    // ===============================================================

    public function test_index_requires_authentication(): void
    {
        $this->get('/timesheets')->assertRedirect('/login');
    }

    public function test_create_requires_authentication(): void
    {
        $this->get('/timesheets/create')->assertRedirect('/login');
    }

    public function test_store_requires_authentication(): void
    {
        $this->post('/timesheets', $this->validTimesheetData())->assertRedirect('/login');
    }

    public function test_show_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->create();
        $this->get("/timesheets/{$timesheet->id}")->assertRedirect('/login');
    }

    public function test_edit_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->create();
        $this->get("/timesheets/{$timesheet->id}/edit")->assertRedirect('/login');
    }

    public function test_update_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->create();
        $this->put("/timesheets/{$timesheet->id}", $this->validTimesheetData())->assertRedirect('/login');
    }

    public function test_submit_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->create();
        $this->post("/timesheets/{$timesheet->id}/submit")->assertRedirect('/login');
    }

    public function test_approve_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();
        $this->post("/timesheets/{$timesheet->id}/approve")->assertRedirect('/login');
    }

    public function test_reject_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();
        $this->post("/timesheets/{$timesheet->id}/reject", [
            'decision_notes' => 'Rejected',
        ])->assertRedirect('/login');
    }

    public function test_return_for_changes_requires_authentication(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();
        $this->post("/timesheets/{$timesheet->id}/return", [
            'returned_notes' => 'Fix it',
        ])->assertRedirect('/login');
    }

    public function test_approvals_requires_authentication(): void
    {
        $this->get('/timesheets/approvals')->assertRedirect('/login');
    }

    public function test_bulk_approve_requires_authentication(): void
    {
        $this->post('/timesheets/bulk-approve', ['ids' => [1]])->assertRedirect('/login');
    }

    public function test_bulk_return_requires_authentication(): void
    {
        $this->post('/timesheets/bulk-return', [
            'ids' => [1],
            'returned_notes' => 'Fix it',
        ])->assertRedirect('/login');
    }

    public function test_bulk_reject_requires_authentication(): void
    {
        $this->post('/timesheets/bulk-reject', [
            'ids' => [1],
            'decision_notes' => 'Rejected',
        ])->assertRedirect('/login');
    }

    // ===============================================================
    // 2. ROLE-BASED ACCESS
    // ===============================================================

    public function test_admin_can_view_all_timesheets(): void
    {
        Timesheet::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/index')
            ->has('timesheets.data', 3)
        );
    }

    public function test_staff_sees_only_own_timesheets_on_index(): void
    {
        // Own timesheet
        Timesheet::factory()->create(['user_id' => $this->staff->id]);
        // Someone else's timesheet
        Timesheet::factory()->create(['user_id' => $this->otherStaff->id]);

        $response = $this->actingAs($this->staff)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/index')
            ->has('timesheets.data', 1)
        );
    }

    public function test_finance_can_view_all_timesheets(): void
    {
        Timesheet::factory()->count(2)->create();

        $response = $this->actingAs($this->finance)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/index')
        );
    }

    public function test_finance_can_approve_timesheets(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'approved',
            'approved_by' => $this->finance->id,
        ]);
    }

    public function test_staff_cannot_approve_timesheets(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertForbidden();
    }

    public function test_staff_cannot_reject_timesheets(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/reject", [
                'decision_notes' => 'Rejected',
            ]);

        $response->assertForbidden();
    }

    public function test_staff_cannot_return_timesheets(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/return", [
                'returned_notes' => 'Fix it',
            ]);

        $response->assertForbidden();
    }

    public function test_coordinator_can_approve_timesheets(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->coordinator)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'approved',
            'approved_by' => $this->coordinator->id,
        ]);
    }

    public function test_index_returns_can_approve_flag_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canApprove', true)
        );
    }

    public function test_index_returns_can_approve_flag_for_finance(): void
    {
        $response = $this->actingAs($this->finance)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canApprove', true)
        );
    }

    public function test_index_returns_can_approve_false_for_staff(): void
    {
        $response = $this->actingAs($this->staff)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canApprove', false)
        );
    }

    public function test_index_returns_can_create_flag_for_staff(): void
    {
        $response = $this->actingAs($this->staff)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canCreate', true)
        );
    }

    public function test_index_returns_clients_and_staff_lists_for_approvers(): void
    {
        $response = $this->actingAs($this->admin)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('clients')
            ->has('staff')
        );
    }

    // ===============================================================
    // 3. FULL CRUD WORKFLOW
    // ===============================================================

    public function test_create_page_renders(): void
    {
        $response = $this->actingAs($this->staff)->get('/timesheets/create');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/create')
            ->has('clients')
        );
    }

    public function test_store_creates_draft_timesheet(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData());

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'break_minutes' => 30,
            'notes' => 'Test shift notes',
            'created_by' => $this->staff->id,
        ]);
    }

    public function test_store_can_mark_timesheet_as_residential_billable(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['is_residential_billable' => true]));

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->staff->id,
            'is_residential_billable' => true,
        ]);
    }

    public function test_store_without_shift_id_creates_timesheet(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['shift_id' => null]));

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => null,
            'status' => 'draft',
        ]);
    }

    public function test_store_defaults_break_minutes_to_zero(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['break_minutes' => null]));

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->staff->id,
            'break_minutes' => 0,
        ]);
    }

    public function test_show_delegates_to_edit(): void
    {
        $timesheet = Timesheet::factory()->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/edit')
        );
    }

    public function test_edit_page_renders_with_timesheet_data(): void
    {
        $timesheet = Timesheet::factory()->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/edit')
            ->has('timesheet')
            ->has('clients')
        );
    }

    public function test_update_modifies_draft_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'notes' => 'Old notes',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 45,
                'notes' => 'Updated notes',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Updated notes',
            'break_minutes' => 45,
        ]);
    }

    public function test_update_can_change_residential_billable_flag(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
            'is_residential_billable' => false,
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 0,
                'notes' => $timesheet->notes,
                'is_residential_billable' => true,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'is_residential_billable' => true,
        ]);
    }

    public function test_admin_can_store_timesheet(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/timesheets', $this->validTimesheetData());

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'client_id' => $this->client->id,
            'status' => 'draft',
        ]);
    }

    // ===============================================================
    // 4. VALIDATION
    // ===============================================================

    public function test_store_validates_required_client_id(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['client_id' => null]));

        $response->assertSessionHasErrors(['client_id']);
    }

    public function test_store_validates_required_work_date(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['work_date' => null]));

        $response->assertSessionHasErrors(['work_date']);
    }

    public function test_store_validates_required_starts_at(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['starts_at' => null]));

        $response->assertSessionHasErrors(['starts_at']);
    }

    public function test_store_validates_required_ends_at(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['ends_at' => null]));

        $response->assertSessionHasErrors(['ends_at']);
    }

    public function test_store_validates_ends_at_after_starts_at(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData([
                'starts_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            ]));

        $response->assertSessionHasErrors(['ends_at']);
    }

    public function test_store_validates_break_minutes_max(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['break_minutes' => 601]));

        $response->assertSessionHasErrors(['break_minutes']);
    }

    public function test_store_validates_break_minutes_min(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['break_minutes' => -1]));

        $response->assertSessionHasErrors(['break_minutes']);
    }

    public function test_store_validates_break_minutes_at_max_boundary(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['break_minutes' => 600]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_store_validates_client_exists(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['client_id' => 99999]));

        $response->assertSessionHasErrors(['client_id']);
    }

    public function test_store_validates_shift_exists(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['shift_id' => 99999]));

        $response->assertSessionHasErrors(['shift_id']);
    }

    public function test_store_validates_all_required_fields_at_once(): void
    {
        $response = $this->actingAs($this->staff)->post('/timesheets', []);

        $response->assertSessionHasErrors([
            'client_id',
            'work_date',
            'starts_at',
            'ends_at',
        ]);
    }

    public function test_store_validates_work_date_is_valid_date(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['work_date' => 'not-a-date']));

        $response->assertSessionHasErrors(['work_date']);
    }

    public function test_update_validates_required_fields(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", []);

        $response->assertSessionHasErrors([
            'client_id',
            'work_date',
            'starts_at',
            'ends_at',
        ]);
    }

    public function test_update_validates_ends_at_after_starts_at(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            ]);

        $response->assertSessionHasErrors(['ends_at']);
    }

    public function test_update_validates_break_minutes_max(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 601,
            ]);

        $response->assertSessionHasErrors(['break_minutes']);
    }

    // ===============================================================
    // 5. APPROVAL WORKFLOW: draft -> submit -> approve
    // ===============================================================

    public function test_full_approval_workflow(): void
    {
        // Step 1: Create draft timesheet
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData());
        $response->assertRedirect();

        $timesheet = Timesheet::latest('id')->first();
        $this->assertEquals('returned', $timesheet->status);

        // Step 2: Submit
        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('submitted', $timesheet->status);
        $this->assertNotNull($timesheet->submitted_at);
        $this->assertEquals($this->staff->id, $timesheet->submitted_by);

        // Step 3: Approve
        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/approve", [
                'decision_notes' => 'Looks good',
            ]);
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('approved', $timesheet->status);
        $this->assertNotNull($timesheet->approved_at);
        $this->assertEquals($this->finance->id, $timesheet->approved_by);
        $this->assertEquals('Looks good', $timesheet->decision_notes);
    }

    // ===============================================================
    // 6. REJECTION WORKFLOW: draft -> submit -> reject
    // ===============================================================

    public function test_full_rejection_workflow(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        // Step 1: Submit
        $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $timesheet->refresh();
        $this->assertEquals('submitted', $timesheet->status);

        // Step 2: Reject with decision_notes
        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/reject", [
                'decision_notes' => 'Hours are incorrect',
            ]);
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('rejected', $timesheet->status);
        $this->assertNotNull($timesheet->approved_at);
        $this->assertEquals($this->finance->id, $timesheet->approved_by);
        $this->assertEquals('Hours are incorrect', $timesheet->decision_notes);
    }

    public function test_reject_accepts_rejection_reason_field(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/reject", [
                'rejection_reason' => 'Wrong client assigned',
            ]);
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('rejected', $timesheet->status);
        $this->assertEquals('Wrong client assigned', $timesheet->decision_notes);
    }

    public function test_reject_requires_notes(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/reject", []);

        $response->assertSessionHasErrors(['decision_notes']);
    }

    // ===============================================================
    // 7. RETURN WORKFLOW: draft -> submit -> return -> resubmit -> approve
    // ===============================================================

    public function test_full_return_and_resubmit_workflow(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'status' => 'draft',
        ]);

        // Step 1: Submit
        $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");
        $timesheet->refresh();
        $this->assertEquals('submitted', $timesheet->status);

        // Step 2: Return for changes
        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/return", [
                'returned_notes' => 'Please add break time details',
            ]);
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('returned', $timesheet->status);
        $this->assertNotNull($timesheet->returned_at);
        $this->assertEquals($this->admin->id, $timesheet->returned_by);
        $this->assertEquals('Please add break time details', $timesheet->returned_notes);
        // Approval fields should be cleared
        $this->assertNull($timesheet->approved_by);
        $this->assertNull($timesheet->approved_at);
        $this->assertNull($timesheet->decision_notes);

        // Step 3: Edit the returned timesheet
        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => 60,
                'notes' => 'Updated with break details',
            ]);
        $response->assertRedirect();

        // Step 4: Resubmit
        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('submitted', $timesheet->status);
        // Prior decision fields should be cleared on resubmit
        $this->assertNull($timesheet->approved_by);
        $this->assertNull($timesheet->approved_at);
        $this->assertNull($timesheet->decision_notes);
        $this->assertNull($timesheet->returned_at);
        $this->assertNull($timesheet->returned_by);
        $this->assertNull($timesheet->returned_notes);

        // Step 5: Approve
        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/approve");
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('approved', $timesheet->status);
    }

    public function test_return_for_changes_accepts_return_reason_field(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/return", [
                'return_reason' => 'Missing information',
            ]);
        $response->assertRedirect();

        $timesheet->refresh();
        $this->assertEquals('returned', $timesheet->status);
        $this->assertEquals('Missing information', $timesheet->returned_notes);
    }

    public function test_return_for_changes_requires_notes(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/return", []);

        $response->assertSessionHasErrors(['returned_notes']);
    }

    // ===============================================================
    // 8. CANNOT UPDATE APPROVED/SUBMITTED TIMESHEETS
    // ===============================================================

    public function test_cannot_update_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Trying to modify approved',
            ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Trying to modify approved',
        ]);
    }

    public function test_cannot_update_submitted_timesheet(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Trying to modify submitted',
            ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Trying to modify submitted',
        ]);
    }

    public function test_can_update_returned_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'returned',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Updated after return',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Updated after return',
        ]);
    }

    public function test_cannot_update_rejected_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'rejected',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Trying to modify rejected',
            ]);

        $response->assertSessionHas('error');
    }

    // Even admin cannot bypass status guard on update
    public function test_admin_cannot_update_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create();

        $response = $this->actingAs($this->admin)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Admin trying to modify approved',
            ]);

        $response->assertSessionHas('error');
    }

    // ===============================================================
    // 9. OWNERSHIP CHECKS
    // ===============================================================

    public function test_staff_cannot_view_others_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->otherStaff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertForbidden();
    }

    public function test_staff_cannot_update_others_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->otherStaff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Hijacking notes',
            ]);

        $response->assertForbidden();
    }

    public function test_staff_cannot_submit_others_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->otherStaff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertForbidden();
    }

    public function test_admin_can_view_any_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->otherStaff->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
    }

    public function test_admin_can_update_any_draft_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->otherStaff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Admin edited this',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Admin edited this',
        ]);
    }

    public function test_admin_can_submit_any_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->otherStaff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'submitted',
        ]);
    }

    public function test_staff_cannot_create_timesheet_from_others_shift(): void
    {
        $otherShift = Shift::factory()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData(['shift_id' => $otherShift->id]));

        $response->assertForbidden();
    }

    public function test_admin_can_create_timesheet_from_others_shift(): void
    {
        $otherShift = Shift::factory()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/timesheets', $this->validTimesheetData(['shift_id' => $otherShift->id]));

        $response->assertRedirect();
        // When created from another user's shift, user_id should be the shift owner
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->otherStaff->id,
            'shift_id' => $otherShift->id,
        ]);
    }

    // ===============================================================
    // 10. BULK APPROVE / REJECT / RETURN
    // ===============================================================

    public function test_bulk_approve_approves_submitted_timesheets(): void
    {
        $t1 = Timesheet::factory()->submitted()->create();
        $t2 = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post('/timesheets/bulk-approve', [
                'ids' => [$t1->id, $t2->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', ['id' => $t1->id, 'status' => 'approved', 'approved_by' => $this->finance->id]);
        $this->assertDatabaseHas('timesheets', ['id' => $t2->id, 'status' => 'approved', 'approved_by' => $this->finance->id]);
    }

    public function test_bulk_approve_with_decision_notes(): void
    {
        $t1 = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', [
                'ids' => [$t1->id],
                'decision_notes' => 'All verified',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $t1->id,
            'status' => 'approved',
            'decision_notes' => 'All verified',
        ]);
    }

    public function test_bulk_reject_rejects_submitted_timesheets(): void
    {
        $t1 = Timesheet::factory()->submitted()->create();
        $t2 = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-reject', [
                'ids' => [$t1->id, $t2->id],
                'decision_notes' => 'Bulk rejected: incorrect hours',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', ['id' => $t1->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('timesheets', ['id' => $t2->id, 'status' => 'rejected']);
    }

    public function test_bulk_return_returns_submitted_timesheets(): void
    {
        $t1 = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
        ]);
        $otherShift = Shift::factory()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $t2 = Timesheet::factory()->submitted()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
            'shift_id' => $otherShift->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-return', [
                'ids' => [$t1->id, $t2->id],
                'returned_notes' => 'Bulk returned: need more detail',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('timesheets', [
            'id' => $t1->id,
            'status' => 'returned',
            'returned_notes' => 'Bulk returned: need more detail',
        ]);
        $this->assertDatabaseHas('timesheets', [
            'id' => $t2->id,
            'status' => 'returned',
            'returned_notes' => 'Bulk returned: need more detail',
        ]);
    }

    public function test_bulk_approve_requires_ids(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', []);

        $response->assertSessionHasErrors(['ids']);
    }

    public function test_bulk_approve_requires_valid_ids(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', [
                'ids' => [99999],
            ]);

        $response->assertSessionHasErrors(['ids.0']);
    }

    public function test_bulk_approve_requires_non_empty_ids_array(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', [
                'ids' => [],
            ]);

        $response->assertSessionHasErrors(['ids']);
    }

    public function test_staff_cannot_bulk_approve(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->staff)
            ->post('/timesheets/bulk-approve', ['ids' => [$t->id]]);

        $response->assertForbidden();
    }

    public function test_staff_cannot_bulk_reject(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->staff)
            ->post('/timesheets/bulk-reject', [
                'ids' => [$t->id],
                'decision_notes' => 'Rejected',
            ]);

        $response->assertForbidden();
    }

    public function test_staff_cannot_bulk_return(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->staff)
            ->post('/timesheets/bulk-return', [
                'ids' => [$t->id],
                'returned_notes' => 'Fix it',
            ]);

        $response->assertForbidden();
    }

    // ===============================================================
    // 11. BULK OPERATIONS SKIP NON-SUBMITTED TIMESHEETS
    // ===============================================================

    public function test_bulk_approve_skips_draft_timesheets(): void
    {
        $draft = Timesheet::factory()->create(['status' => 'draft']);
        $submitted = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', [
                'ids' => [$draft->id, $submitted->id],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', ['id' => $draft->id, 'status' => 'draft']);
        $this->assertDatabaseHas('timesheets', ['id' => $submitted->id, 'status' => 'approved']);
    }

    public function test_bulk_approve_skips_already_approved_timesheets(): void
    {
        $approved = Timesheet::factory()->approved()->create();
        $submitted = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', [
                'ids' => [$approved->id, $submitted->id],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', ['id' => $submitted->id, 'status' => 'approved']);
    }

    public function test_bulk_reject_skips_draft_timesheets(): void
    {
        $draft = Timesheet::factory()->create(['status' => 'draft']);
        $submitted = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-reject', [
                'ids' => [$draft->id, $submitted->id],
                'decision_notes' => 'Rejected',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', ['id' => $draft->id, 'status' => 'draft']);
        $this->assertDatabaseHas('timesheets', ['id' => $submitted->id, 'status' => 'rejected']);
    }

    public function test_bulk_return_skips_draft_timesheets(): void
    {
        $draft = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'status' => 'draft',
        ]);
        $otherShift = Shift::factory()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $submitted = Timesheet::factory()->submitted()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
            'shift_id' => $otherShift->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-return', [
                'ids' => [$draft->id, $submitted->id],
                'returned_notes' => 'Fix it',
            ]);

        $response->assertRedirect();
        // Draft stays as draft (skip), submitted becomes returned.
        $draft->refresh();
        $submitted->refresh();
        $this->assertEquals('draft', $draft->status);
        $this->assertNull($draft->returned_notes);
        $this->assertEquals('returned', $submitted->status);
        $this->assertEquals('Fix it', $submitted->returned_notes);
    }

    // ===============================================================
    // 12. BULK OPERATIONS REQUIRE NOTES WHERE NEEDED
    // ===============================================================

    public function test_bulk_reject_requires_decision_notes(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-reject', [
                'ids' => [$t->id],
            ]);

        $response->assertSessionHasErrors(['decision_notes']);
    }

    public function test_bulk_return_requires_returned_notes(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-return', [
                'ids' => [$t->id],
            ]);

        $response->assertSessionHasErrors(['returned_notes']);
    }

    public function test_bulk_approve_does_not_require_decision_notes(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post('/timesheets/bulk-approve', [
                'ids' => [$t->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    // ===============================================================
    // 13. APPROVAL MODE FILTER
    // ===============================================================

    public function test_index_approval_mode_filters_submitted_only(): void
    {
        Timesheet::factory()->create(['status' => 'draft']);
        Timesheet::factory()->submitted()->create();
        Timesheet::factory()->approved()->create();

        $response = $this->actingAs($this->admin)
            ->get('/timesheets?mode=approvals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/index')
            ->where('approvalMode', true)
            ->has('timesheets.data', 1)
            ->where('filters.mode', 'approvals')
        );
    }

    public function test_staff_cannot_use_approval_mode(): void
    {
        Timesheet::factory()->submitted()->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->get('/timesheets?mode=approvals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('approvalMode', false)
        );
    }

    public function test_approvals_page_requires_approve_permission(): void
    {
        $response = $this->actingAs($this->staff)
            ->get('/timesheets/approvals');

        $response->assertForbidden();
    }

    public function test_approvals_page_shows_submitted_timesheets(): void
    {
        Timesheet::factory()->submitted()->count(2)->create();
        Timesheet::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($this->finance)
            ->get('/timesheets/approvals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/approvals')
            ->has('timesheets.data', 2)
        );
    }

    public function test_admin_can_access_approvals_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/timesheets/approvals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/approvals')
        );
    }

    // ===============================================================
    // 14. DATE RANGE AND STATUS FILTERS
    // ===============================================================

    public function test_index_filters_by_status(): void
    {
        Timesheet::factory()->create(['status' => 'draft']);
        Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->get('/timesheets?status=draft');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 1)
            ->where('filters.status', 'draft')
        );
    }

    public function test_index_filters_by_date_from(): void
    {
        Timesheet::factory()->create([
            'work_date' => now()->subDays(10),
        ]);
        Timesheet::factory()->create([
            'work_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/timesheets?from=' . now()->subDay()->format('Y-m-d'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 1)
        );
    }

    public function test_index_filters_by_date_to(): void
    {
        Timesheet::factory()->create([
            'work_date' => now()->subDays(10),
        ]);
        Timesheet::factory()->create([
            'work_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/timesheets?to=' . now()->subDays(5)->format('Y-m-d'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 1)
        );
    }

    public function test_index_filters_by_date_range(): void
    {
        Timesheet::factory()->create(['work_date' => now()->subDays(20)]);
        Timesheet::factory()->create(['work_date' => now()->subDays(5)]);
        Timesheet::factory()->create(['work_date' => now()]);

        $from = now()->subDays(10)->format('Y-m-d');
        $to = now()->subDay()->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->get("/timesheets?from={$from}&to={$to}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 1)
        );
    }

    public function test_index_filters_by_client_id(): void
    {
        $client1 = Client::factory()->create();
        $client2 = Client::factory()->create();
        Timesheet::factory()->create(['client_id' => $client1->id]);
        Timesheet::factory()->create(['client_id' => $client2->id]);

        $response = $this->actingAs($this->admin)
            ->get("/timesheets?client_id={$client1->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 1)
        );
    }

    public function test_index_filters_by_staff_id(): void
    {
        Timesheet::factory()->create(['user_id' => $this->staff->id]);
        Timesheet::factory()->create(['user_id' => $this->otherStaff->id]);

        $response = $this->actingAs($this->admin)
            ->get("/timesheets?staff_id={$this->staff->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 1)
        );
    }

    public function test_index_returns_filter_values_in_response(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/timesheets?status=draft&from=2025-01-01&to=2025-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.status', 'draft')
            ->where('filters.from', '2025-01-01')
            ->where('filters.to', '2025-12-31')
        );
    }

    // ===============================================================
    // 15. PRE-FILL FROM shift_id
    // ===============================================================

    public function test_create_prefills_from_own_shift(): void
    {
        $response = $this->actingAs($this->staff)
            ->get("/timesheets/create?shift_id={$this->shift->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/create')
            ->has('shift')
            ->where('shift.id', $this->shift->id)
        );
    }

    public function test_create_rejects_others_shift_for_staff(): void
    {
        $otherShift = Shift::factory()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/create?shift_id={$otherShift->id}");

        $response->assertForbidden();
    }

    public function test_admin_can_create_from_any_shift(): void
    {
        $otherShift = Shift::factory()->create([
            'user_id' => $this->otherStaff->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/timesheets/create?shift_id={$otherShift->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/create')
            ->has('shift')
            ->where('shift.id', $otherShift->id)
        );
    }

    public function test_create_without_shift_id_shows_no_shift(): void
    {
        $response = $this->actingAs($this->staff)
            ->get('/timesheets/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/create')
            ->where('shift', null)
        );
    }

    public function test_create_with_invalid_shift_id_returns_404(): void
    {
        $response = $this->actingAs($this->staff)
            ->get('/timesheets/create?shift_id=99999');

        $response->assertNotFound();
    }

    // ===============================================================
    // 16. EDIT RETURNS CORRECT PERMISSION FLAGS
    // ===============================================================

    public function test_edit_returns_can_approve_true_for_admin(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canApprove', true)
        );
    }

    public function test_edit_returns_can_approve_true_for_finance(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->finance->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->finance)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canApprove', true)
        );
    }

    public function test_edit_returns_can_approve_false_for_staff(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canApprove', false)
        );
    }

    public function test_edit_returns_can_submit_true_for_owner(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canSubmit', true)
        );
    }

    public function test_edit_returns_can_edit_true_for_draft_owner(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canEdit', true)
        );
    }

    public function test_edit_returns_can_edit_true_for_returned_owner(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'returned',
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canEdit', true)
        );
    }

    public function test_edit_returns_can_edit_false_for_submitted(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canEdit', false)
        );
    }

    public function test_edit_returns_can_edit_false_for_approved(): void
    {
        $timesheet = Timesheet::factory()->approved()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canEdit', false)
        );
    }

    public function test_edit_returns_timesheet_and_clients(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/edit')
            ->has('timesheet')
            ->has('clients')
            ->where('timesheet.id', $timesheet->id)
        );
    }

    // ===============================================================
    // 17. CANNOT SUBMIT NON-DRAFT TIMESHEETS
    // ===============================================================

    public function test_cannot_submit_submitted_timesheet(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertForbidden();
    }

    public function test_cannot_submit_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertForbidden();
    }

    public function test_cannot_submit_rejected_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'rejected',
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertForbidden();
    }

    public function test_can_submit_returned_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'status' => 'returned',
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'submitted',
        ]);
    }

    // ===============================================================
    // 18. CANNOT APPROVE NON-SUBMITTED TIMESHEETS
    // ===============================================================

    public function test_cannot_approve_draft_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertForbidden();
    }

    public function test_cannot_approve_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create();

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertForbidden();
    }

    public function test_cannot_approve_rejected_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create(['status' => 'rejected']);

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertForbidden();
    }

    public function test_cannot_reject_draft_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/reject", [
                'decision_notes' => 'Rejected',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_reject_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create();

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/reject", [
                'decision_notes' => 'Rejected',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_return_draft_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/return", [
                'returned_notes' => 'Fix it',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_return_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create();

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/return", [
                'returned_notes' => 'Fix it',
            ]);

        $response->assertForbidden();
    }

    // ===============================================================
    // ADDITIONAL EDGE CASES
    // ===============================================================

    public function test_submit_clears_prior_decision_fields(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
            'approved_by' => $this->admin->id,
            'approved_at' => now()->subDay(),
            'decision_notes' => 'Previously approved',
            'returned_at' => now()->subDays(2),
            'returned_by' => $this->admin->id,
            'returned_notes' => 'Previously returned',
        ]);

        $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $timesheet->refresh();
        $this->assertEquals('submitted', $timesheet->status);
        $this->assertNull($timesheet->approved_by);
        $this->assertNull($timesheet->approved_at);
        $this->assertNull($timesheet->decision_notes);
        $this->assertNull($timesheet->returned_at);
        $this->assertNull($timesheet->returned_by);
        $this->assertNull($timesheet->returned_notes);
    }

    public function test_return_for_changes_clears_approval_fields(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'decision_notes' => 'Was previously noted',
        ]);

        $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/return", [
                'returned_notes' => 'Needs correction',
            ]);

        $timesheet->refresh();
        $this->assertEquals('returned', $timesheet->status);
        $this->assertNull($timesheet->approved_by);
        $this->assertNull($timesheet->approved_at);
        $this->assertNull($timesheet->decision_notes);
    }

    public function test_approve_records_approver_and_timestamp(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/approve", [
                'decision_notes' => 'All good',
            ]);

        $timesheet->refresh();
        $this->assertEquals($this->finance->id, $timesheet->approved_by);
        $this->assertNotNull($timesheet->approved_at);
        $this->assertEquals('All good', $timesheet->decision_notes);
    }

    public function test_approve_without_notes_is_allowed(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertRedirect();
        $timesheet->refresh();
        $this->assertEquals('approved', $timesheet->status);
        $this->assertNull($timesheet->decision_notes);
    }

    public function test_finance_cannot_create_timesheets(): void
    {
        $response = $this->actingAs($this->finance)
            ->get('/timesheets/create');

        $response->assertForbidden();
    }

    public function test_finance_cannot_store_timesheets(): void
    {
        $response = $this->actingAs($this->finance)
            ->post('/timesheets', $this->validTimesheetData());

        $response->assertForbidden();
    }

    public function test_finance_cannot_update_timesheets(): void
    {
        $timesheet = Timesheet::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($this->finance)
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'notes' => 'Finance should not edit',
            ]);

        $response->assertForbidden();
    }

    public function test_store_sets_user_id_from_shift_owner(): void
    {
        // When creating from a shift, user_id is the shift's user, not the creator
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData([
                'shift_id' => $this->shift->id,
            ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->staff->id,   // shift owner
            'shift_id' => $this->shift->id,
            'created_by' => $this->staff->id, // creator
        ]);
    }

    public function test_store_redirects_to_edit_page(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $this->validTimesheetData());

        $timesheet = Timesheet::latest('id')->first();
        $response->assertRedirect(route('timesheets.edit', $timesheet));
    }

    public function test_submit_records_submitter_and_timestamp(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $timesheet->refresh();
        $this->assertEquals($this->staff->id, $timesheet->submitted_by);
        $this->assertNotNull($timesheet->submitted_at);
    }

    public function test_bulk_return_sets_returned_by_and_clears_approval(): void
    {
        $t = Timesheet::factory()->submitted()->create();

        $this->actingAs($this->admin)
            ->post('/timesheets/bulk-return', [
                'ids' => [$t->id],
                'returned_notes' => 'Needs more info',
            ]);

        $t->refresh();
        $this->assertEquals($this->admin->id, $t->returned_by);
        $this->assertNotNull($t->returned_at);
        $this->assertNull($t->approved_by);
        $this->assertNull($t->approved_at);
        $this->assertNull($t->decision_notes);
    }

    public function test_nonexistent_timesheet_returns_404(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/timesheets/99999/edit');

        $response->assertNotFound();
    }

    public function test_index_paginates_results(): void
    {
        Timesheet::factory()->count(30)->create();

        $response = $this->actingAs($this->admin)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timesheets.data', 25) // paginate(25)
        );
    }
}
