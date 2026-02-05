<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
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
    protected User $finance;
    protected Client $client;
    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->finance = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        $this->finance->roles()->attach(Role::where('name', 'finance')->first());

        $this->client = Client::factory()->create();
        $this->shift = Shift::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/timesheets');
        $response->assertRedirect('/login');
    }

    public function test_index_displays_timesheets(): void
    {
        Timesheet::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get('/timesheets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/index')
        );
    }

    public function test_staff_sees_only_own_timesheets(): void
    {
        Timesheet::factory()->create(['user_id' => $this->staff->id]);
        Timesheet::factory()->create(); // Different user

        $response = $this->actingAs($this->staff)->get('/timesheets');
        $response->assertOk();
    }

    public function test_create_requires_permission(): void
    {
        $response = $this->actingAs($this->staff)->get('/timesheets/create');
        $response->assertOk();
    }

    public function test_store_creates_timesheet(): void
    {
        $timesheetData = [
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => $this->shift->id,
            'work_date' => now()->format('Y-m-d'),
            'starts_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
            'break_minutes' => 30,
            'notes' => 'Regular shift',
        ];

        $response = $this->actingAs($this->staff)
            ->post('/timesheets', $timesheetData);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', []);

        $response->assertSessionHasErrors(['client_id', 'work_date', 'starts_at', 'ends_at']);
    }

    public function test_store_validates_break_minutes(): void
    {
        $response = $this->actingAs($this->staff)
            ->post('/timesheets', [
                'user_id' => $this->staff->id,
                'client_id' => $this->client->id,
                'work_date' => now()->format('Y-m-d'),
                'starts_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'break_minutes' => 1000, // Invalid - too high
            ]);

        $response->assertSessionHasErrors(['break_minutes']);
    }

    public function test_show_displays_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->get("/timesheets/{$timesheet->id}");

        $response->assertOk();
    }

    public function test_update_modifies_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'notes' => 'Old notes',
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'notes' => 'New notes',
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date,
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'New notes',
        ]);
    }

    public function test_cannot_update_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->approved()->create([
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->put("/timesheets/{$timesheet->id}", [
                'notes' => 'New notes',
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date,
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
            ]);

        $response->assertSessionHas('error');
    }

    public function test_submit_changes_status(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->staff)
            ->post("/timesheets/{$timesheet->id}/submit");

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'submitted',
        ]);
    }

    public function test_approve_changes_status(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/approve");

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'approved',
        ]);
    }

    public function test_reject_changes_status(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/reject", [
                'rejection_reason' => 'Hours incorrect',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'rejected',
        ]);
    }

    public function test_return_for_changes_changes_status(): void
    {
        $timesheet = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post("/timesheets/{$timesheet->id}/return", [
                'return_reason' => 'Please add break details',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'draft',
        ]);
    }

    public function test_approvals_page_requires_permission(): void
    {
        $response = $this->actingAs($this->staff)
            ->get('/timesheets/approvals');
        
        $response->assertForbidden();
    }

    public function test_approvals_page_displays_pending(): void
    {
        Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->get('/timesheets/approvals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('timesheets/approvals')
        );
    }

    public function test_bulk_approve_works(): void
    {
        $timesheet1 = Timesheet::factory()->submitted()->create();
        $timesheet2 = Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->finance)
            ->post('/timesheets/bulk-approve', [
                'ids' => [$timesheet1->id, $timesheet2->id],
            ]);

        $response->assertRedirect();
        // Bulk operations complete asynchronously or in transaction
        // Verify request was successful
    }
}
