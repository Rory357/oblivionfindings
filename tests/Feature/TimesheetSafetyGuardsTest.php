<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetSafetyGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Site $site;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportRole = Role::where('name', 'support_worker')->first();
        $this->staff->roles()->attach($supportRole);

        $supportPermissionIds = Permission::query()
            ->whereIn('key', ['timesheets.create', 'timesheets.submit'])
            ->pluck('id')
            ->all();
        if ($supportPermissionIds) {
            $supportRole->permissions()->syncWithoutDetaching($supportPermissionIds);
        }

        $this->site = Site::factory()->create(['name' => 'Safety Guard House']);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'employee_number' => 'EMP-TS-'.$this->staff->id,
            'work_email' => $this->staff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
    }

    public function test_self_approval_is_blocked(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->admin->id,
        ]);

        $timesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/operations/timesheets/{$timesheet->id}/approve")
            ->assertForbidden();

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'submitted',
            'approved_by' => null,
        ]);
    }

    public function test_bulk_self_approval_is_blocked(): void
    {
        $ownShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->admin->id,
        ]);
        $otherShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $ownTimesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $ownShift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->admin->id,
        ]);
        $otherTimesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $otherShift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/operations/timesheets/bulk-approve', [
                'ids' => [$ownTimesheet->id, $otherTimesheet->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('timesheets', [
            'id' => $ownTimesheet->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('timesheets', [
            'id' => $otherTimesheet->id,
            'status' => 'submitted',
        ]);
    }

    public function test_duplicate_timesheet_creation_is_prevented(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $this->from('/operations/timesheets/create')
            ->actingAs($this->staff)
            ->post('/operations/timesheets', $this->timesheetPayload($shift))
            ->assertRedirect();

        $this->assertSame(
            1,
            Timesheet::query()
                ->where('shift_id', $shift->id)
                ->where('user_id', $this->staff->id)
                ->count()
        );
    }

    public function test_timesheet_cannot_be_submitted_if_linked_shift_is_cancelled(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'cancelled',
        ]);

        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->staff)
            ->post("/operations/timesheets/{$timesheet->id}/submit")
            ->assertStatus(422);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'draft',
        ]);
    }

    public function test_timesheet_cannot_be_approved_if_linked_shift_is_cancelled(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'scheduled',
        ]);

        $timesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $shift->update(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->post("/operations/timesheets/{$timesheet->id}/approve")
            ->assertStatus(422);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'submitted',
            'approved_by' => null,
        ]);
    }

    public function test_shift_cannot_be_edited_after_approved_timesheet_exists(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(17, 0),
            'status' => 'scheduled',
        ]);

        Timesheet::factory()->approved()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $newStart = $shift->starts_at->copy()->addHour();
        $newEnd = $shift->ends_at->copy()->addHour();

        $this->from("/operations/shifts/{$shift->id}")
            ->actingAs($this->admin)
            ->put("/operations/shifts/{$shift->id}", [
                'client_id' => $this->client->id,
                'user_id' => $this->staff->id,
                'starts_at' => $newStart->format('Y-m-d H:i:s'),
                'ends_at' => $newEnd->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
            ])
            ->assertRedirect("/operations/shifts/{$shift->id}")
            ->assertSessionHasErrors(['shift']);

        $shift->refresh();
        $this->assertTrue($shift->starts_at->ne($newStart));
        $this->assertTrue($shift->ends_at->ne($newEnd));
    }

    /**
     * @return array<string, mixed>
     */
    protected function timesheetPayload(Shift $shift): array
    {
        return [
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
            'work_date' => now()->format('Y-m-d'),
            'starts_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
            'break_minutes' => 30,
            'notes' => 'Safety guard test',
        ];
    }
}
