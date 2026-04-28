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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollLockTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('payrollCriticalFields')]
    public function test_approved_timesheets_lock_payroll_critical_shift_fields(
        string $field,
        mixed $replacement,
    ): void {
        $shift = $this->shiftWithApprovedTimesheet();

        $shift->setAttribute(
            $field,
            $replacement instanceof \Closure ? $replacement() : $replacement,
        );

        $this->expectException(ValidationException::class);

        $shift->save();
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function payrollCriticalFields(): array
    {
        return [
            'client_id' => ['client_id', fn () => Client::factory()->create(['organization_id' => 1])->id],
            'site_id' => ['site_id', fn () => Site::factory()->create()->id],
            'service_context_id' => ['service_context_id', fn () => ServiceContext::factory()->create()->id],
            'user_id' => ['user_id', fn () => User::factory()->create(['organization_id' => 1])->id],
            'starts_at' => ['starts_at', Carbon::parse('2026-04-08 10:00:00')],
            'ends_at' => ['ends_at', Carbon::parse('2026-04-08 18:00:00')],
            'shift_type' => ['shift_type', 'complex_care'],
            'is_sleepover' => ['is_sleepover', true],
            'is_on_call' => ['is_on_call', true],
            'expected_break_minutes' => ['expected_break_minutes', 45],
        ];
    }

    private function shiftWithApprovedTimesheet(): Shift
    {
        $staff = User::factory()->create(['organization_id' => 1]);
        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create(['name' => 'Residential Support']);
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
            'starts_at' => Carbon::parse('2026-04-08 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-08 17:00:00'),
            'status' => 'scheduled',
            'shift_type' => 'standard',
            'is_sleepover' => false,
            'is_on_call' => false,
            'expected_break_minutes' => 30,
            'created_by' => $staff->id,
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'shift_service_context_id' => $serviceContext->id,
            'work_date' => '2026-04-08',
            'starts_at' => Carbon::parse('2026-04-08 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-08 17:00:00'),
            'break_minutes' => 30,
            'status' => 'approved',
            'approved_at' => Carbon::parse('2026-04-09 09:00:00'),
            'approved_by' => $staff->id,
            'shift_site_name_snapshot' => $site->name,
            'shift_location_snapshot' => 'Kowhai House',
            'service_context_name_snapshot' => $serviceContext->name,
            'client_name_snapshot' => $client->full_name,
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => ['support_worker'],
        ]);

        return $shift->fresh();
    }
}
