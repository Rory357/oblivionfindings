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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimesheetSnapshotImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('snapshotFields')]
    public function test_approved_timesheet_snapshot_fields_are_immutable(
        string $field,
        mixed $replacement,
    ): void {
        $timesheet = $this->approvedTimesheet();

        $timesheet->setAttribute($field, $replacement);

        $this->expectException(\LogicException::class);

        $timesheet->save();
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function snapshotFields(): array
    {
        return [
            'shift_site_name_snapshot' => ['shift_site_name_snapshot', 'Changed Site'],
            'shift_location_snapshot' => ['shift_location_snapshot', 'Changed Location'],
            'service_context_name_snapshot' => ['service_context_name_snapshot', 'Changed Context'],
            'client_name_snapshot' => ['client_name_snapshot', 'Changed Client'],
            'staff_name_snapshot' => ['staff_name_snapshot', 'Changed Staff'],
            'shift_type_snapshot' => ['shift_type_snapshot', 'changed_type'],
            'coverage_roles_snapshot' => ['coverage_roles_snapshot', ['changed_role']],
        ];
    }

    private function approvedTimesheet(): Timesheet
    {
        $staff = User::factory()->create(['organization_id' => 1]);
        $site = Site::factory()->create(['name' => 'Matai House']);
        $serviceContext = ServiceContext::factory()->create(['name' => 'Residential Support']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'first_name' => 'Aroha',
            'last_name' => 'Jones',
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'location' => 'Matai House',
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        return Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'shift_service_context_id' => $serviceContext->id,
            'work_date' => '2026-04-10',
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'break_minutes' => 30,
            'status' => 'approved',
            'approved_at' => Carbon::parse('2026-04-11 09:00:00'),
            'approved_by' => $staff->id,
            'shift_site_name_snapshot' => $site->name,
            'shift_location_snapshot' => $shift->location,
            'service_context_name_snapshot' => $serviceContext->name,
            'client_name_snapshot' => $client->full_name,
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => ['support_worker'],
        ]);
    }
}
