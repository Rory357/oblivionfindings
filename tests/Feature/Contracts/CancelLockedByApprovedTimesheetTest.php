<?php

namespace Tests\Feature\Contracts;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CancelLockedByApprovedTimesheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_with_approved_timesheet_cannot_be_cancelled(): void
    {
        $staff = User::factory()->create(['organization_id' => 1]);
        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);

        $shift = Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-09 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-09 17:00:00'),
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'shift_service_context_id' => $serviceContext->id,
            'work_date' => '2026-04-09',
            'starts_at' => Carbon::parse('2026-04-09 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-09 17:00:00'),
            'status' => 'approved',
            'approved_at' => Carbon::parse('2026-04-10 09:00:00'),
            'approved_by' => $staff->id,
            'shift_site_name_snapshot' => $site->name,
            'service_context_name_snapshot' => $serviceContext->name,
            'client_name_snapshot' => $client->full_name,
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ]);

        $shift->status = 'cancelled';

        $this->expectException(ValidationException::class);

        $shift->save();
    }
}
