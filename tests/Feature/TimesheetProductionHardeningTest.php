<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $site;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->site = Site::factory()->create([
            'name' => 'Timesheet Hardening Site',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'position_title' => 'Timesheet Administrator',
            'position_role' => 'coordinator',
            'employment_type' => 'full_time',
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
    }

    public function test_approved_timesheet_cannot_be_edited_through_http(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'status' => 'approved',
            'notes' => 'Locked note',
        ]);

        $response = $this->actingAs($this->admin)
            ->from("/operations/timesheets/{$timesheet->id}/edit")
            ->put("/operations/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Attempted drift',
            ]);

        $response->assertRedirect("/operations/timesheets/{$timesheet->id}/edit");
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Locked note',
            'status' => 'approved',
        ]);
    }

    public function test_payroll_linked_timesheet_cannot_be_edited_through_http(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'payroll_reference' => 'operations-payroll-export:77',
            'notes' => 'Payroll linked',
        ]);

        $response = $this->actingAs($this->admin)
            ->from("/operations/timesheets/{$timesheet->id}/edit")
            ->put("/operations/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Attempted payroll drift',
            ]);

        $response->assertRedirect("/operations/timesheets/{$timesheet->id}/edit");
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Payroll linked',
            'status' => 'draft',
            'payroll_reference' => 'operations-payroll-export:77',
        ]);
    }

    public function test_removing_the_current_site_assignment_blocks_edits_without_drift(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'notes' => 'Retained after assignment removal',
        ])->fresh();
        $before = $timesheet->getRawOriginal();
        $otherSite = Site::factory()->create();
        $this->admin->hrEmployeeProfile->update(['primary_site_id' => $otherSite->id]);
        $actor = $this->admin->fresh(['roles', 'hrEmployeeProfile']);
        $this->assertTrue($actor->canDo('timesheets.update'));

        $this->actingAs($actor)
            ->put("/operations/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Rejected drift after assignment removal',
            ])
            ->assertForbidden();

        $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
    }
}
