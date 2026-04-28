<?php

namespace Tests\Feature\Payroll;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\PayrollExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NzPayrollExportGoldenTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_payroll_export_matches_nz_shift_context_baseline(): void
    {
        Storage::fake(config('filesystems.default'));

        $createdBy = $this->seedPayrollScenario();

        $export = app(PayrollExportService::class)->generate(
            organizationId: 1,
            periodStart: '2026-04-06',
            periodEnd: '2026-04-12',
            format: 'csv',
            createdBy: $createdBy,
        );

        $expected = $this->normalizeCsv(file_get_contents(base_path('tests/fixtures/payroll/2026-04-baseline.csv')));
        $actual = $this->normalizeCsv(Storage::get($export->file_path));

        $this->assertSame($expected, $actual);
        $this->assertSame(4, $export->timesheet_count);
        $this->assertSame('exported', $export->status);
    }

    private function seedPayrollScenario(): int
    {
        config([
            'hr.payroll.default_regular_multiplier' => 1.00,
            'hr.payroll.default_sleepover_flat_rate' => 160,
            'hr.payroll.default_on_call_hourly_rate' => 12,
        ]);

        $staff = User::factory()->create([
            'organization_id' => 1,
            'name' => 'Talia Worker',
            'email' => 'talia.worker@example.test',
            'role' => 'support_worker',
        ]);
        $site = Site::factory()->create(['name' => 'Kowhai House']);
        $serviceContext = ServiceContext::factory()->create(['name' => 'Residential Support']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'first_name' => 'Aroha',
            'last_name' => 'Jones',
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'employee_number' => 'EMP-NZ-001',
            'work_email' => $staff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'hourly_rate' => 30,
            'start_date' => '2026-01-01',
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        HrPayRateRule::query()->create([
            'tenant_id' => 1,
            'name' => 'Contract baseline',
            'is_active' => true,
            'priority' => 10,
            'position_role' => 'support_worker',
            'regular_multiplier' => 1,
            'public_holiday_multiplier' => 1.5,
            'sleepover_flat_rate' => 160,
            'on_call_hourly_rate' => 12,
            'effective_from' => '2026-01-01',
        ]);

        $this->createApprovedTimesheet($staff, $client, $site, $serviceContext, [
            'starts_at' => '2026-04-06 09:00:00',
            'ends_at' => '2026-04-06 17:00:00',
            'break_minutes' => 0,
            'shift_type' => 'standard',
            'coverage_roles' => ['support_worker'],
        ]);

        $this->createApprovedTimesheet($staff, $client, $site, $serviceContext, [
            'starts_at' => '2026-04-07 09:00:00',
            'ends_at' => '2026-04-07 17:00:00',
            'break_minutes' => 30,
            'shift_type' => 'standard',
            'coverage_roles' => ['meds', 'driver'],
        ]);

        $this->createApprovedTimesheet($staff, $client, $site, $serviceContext, [
            'starts_at' => '2026-04-08 09:00:00',
            'ends_at' => '2026-04-08 17:00:00',
            'break_minutes' => 0,
            'shift_type' => 'on_call',
            'is_on_call' => true,
            'on_call' => true,
            'coverage_roles' => ['on_call'],
        ]);

        $this->createApprovedTimesheet($staff, $client, $site, $serviceContext, [
            'starts_at' => '2026-04-09 22:00:00',
            'ends_at' => '2026-04-10 06:00:00',
            'break_minutes' => 0,
            'shift_type' => 'sleepover',
            'is_sleepover' => true,
            'sleepover' => true,
            'coverage_roles' => ['overnight'],
        ]);

        return $staff->id;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createApprovedTimesheet(
        User $staff,
        Client $client,
        Site $site,
        ServiceContext $serviceContext,
        array $context,
    ): Timesheet {
        $startsAt = Carbon::parse($context['starts_at']);
        $endsAt = Carbon::parse($context['ends_at']);
        $coverageRoles = $context['coverage_roles'];

        $shift = Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => 'Kowhai House',
            'status' => 'scheduled',
            'shift_type' => $context['shift_type'],
            'is_sleepover' => $context['is_sleepover'] ?? false,
            'is_on_call' => $context['is_on_call'] ?? false,
            'expected_break_minutes' => $context['break_minutes'],
            'coverage_roles' => $coverageRoles,
            'created_by' => $staff->id,
        ]);

        return Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'shift_service_context_id' => $serviceContext->id,
            'work_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => $context['break_minutes'],
            'sleepover' => $context['sleepover'] ?? false,
            'on_call' => $context['on_call'] ?? false,
            'status' => 'approved',
            'approved_at' => Carbon::parse('2026-04-12 09:00:00'),
            'approved_by' => $staff->id,
            'shift_site_name_snapshot' => $site->name,
            'shift_location_snapshot' => $shift->location,
            'service_context_name_snapshot' => $serviceContext->name,
            'client_name_snapshot' => $client->full_name,
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => $context['shift_type'],
            'coverage_roles_snapshot' => $coverageRoles,
        ]);
    }

    private function normalizeCsv(string $csv): string
    {
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)) ?: []);

        if ($rows === [] || ! isset($rows[0])) {
            return '';
        }

        $header = $rows[0];
        $volatileColumns = [
            'source_timesheet_id',
            'source_shift_id',
            'segment_key',
            'employee_id',
        ];

        $volatileIndexes = collect($volatileColumns)
            ->mapWithKeys(fn (string $column) => [$column => array_search($column, $header, true)])
            ->filter(fn ($index) => $index !== false)
            ->all();

        foreach (array_slice(array_keys($rows), 1) as $rowIndex) {
            foreach ($volatileIndexes as $column => $columnIndex) {
                $rows[$rowIndex][$columnIndex] = match ($column) {
                    'source_timesheet_id' => 'timesheet-'.$rowIndex,
                    'source_shift_id' => 'shift-'.$rowIndex,
                    'segment_key' => 'segment-'.$rowIndex,
                    'employee_id' => 'employee',
                    default => $rows[$rowIndex][$columnIndex],
                };
            }
        }

        $stream = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);

        return str_replace("\r\n", "\n", stream_get_contents($stream) ?: '');
    }
}
