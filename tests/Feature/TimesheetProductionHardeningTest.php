<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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

        $this->client = Client::factory()->create();
    }

    public function test_approved_timesheet_cannot_be_edited_through_http(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'status' => 'approved',
            'notes' => 'Locked note',
        ]);

        $response = $this->actingAs($this->admin)->from("/timesheets/{$timesheet->id}/edit")
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Attempted drift',
            ]);

        $response->assertRedirect("/timesheets/{$timesheet->id}/edit");
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
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'payroll_reference' => 'operations-payroll-export:77',
            'notes' => 'Payroll linked',
        ]);

        $response = $this->actingAs($this->admin)->from("/timesheets/{$timesheet->id}/edit")
            ->put("/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->format('Y-m-d'),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Attempted payroll drift',
            ]);

        $response->assertRedirect("/timesheets/{$timesheet->id}/edit");
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Payroll linked',
            'payroll_reference' => 'operations-payroll-export:77',
        ]);
    }
}
