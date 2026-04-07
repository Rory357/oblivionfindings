<?php

namespace Tests\Unit\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\ShiftReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftReportingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftReportingService::class);
        $this->travelTo(Carbon::parse('2026-04-06 12:00:00'));
    }

    public function test_staff_utilisation_returns_correct_totals(): void
    {
        [$site, $client, $serviceContext] = $this->makeSiteContext();
        $staff = User::factory()->create(['name' => 'Alex Worker']);

        $firstShift = $this->makeShift($site, $client, $serviceContext, $staff, [
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'expected_break_minutes' => 30,
        ]);

        HrAttendanceSession::create([
            'user_id' => $staff->id,
            'shift_id' => $firstShift->id,
            'site_id' => $site->id,
            'clock_in_at' => Carbon::parse('2026-04-06 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-04-06 16:00:00'),
            'break_minutes' => 30,
            'status' => 'closed',
            'source' => 'test',
        ]);

        $secondShift = $this->makeShift($site, $client, $serviceContext, $staff, [
            'starts_at' => Carbon::parse('2026-04-07 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-07 17:00:00'),
            'expected_break_minutes' => 30,
        ]);

        Timesheet::factory()->create([
            'shift_id' => $secondShift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-07',
            'starts_at' => Carbon::parse('2026-04-07 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-07 17:00:00'),
            'break_minutes' => 60,
            'status' => 'draft',
        ]);

        $report = $this->service->build([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'site_id' => $site->id,
        ]);

        $staffReport = $report['staff_utilisation'];

        $this->assertSame(1, $staffReport['total_staff']);
        $this->assertSame(2, $staffReport['total_shifts']);
        $this->assertSame(15.0, $staffReport['total_planned_hours']);
        $this->assertSame(13.5, $staffReport['total_worked_hours']);
        $this->assertSame('Alex Worker', $staffReport['rows'][0]['staff_name']);
        $this->assertSame(13.5, $staffReport['rows'][0]['worked_hours']);
    }

    public function test_coverage_gap_report_uses_required_vs_assigned_counts(): void
    {
        [$site, $client, $serviceContext] = $this->makeSiteContext();
        $staff = User::factory()->create();

        $this->makeShift($site, $client, $serviceContext, $staff, [
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
        ]);

        SiteCoverageRequirement::create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'name' => 'Morning Cover',
            'coverage_type' => 'custom',
            'day_of_week' => 'mon',
            'starts_time' => '10:00',
            'ends_time' => '14:00',
            'minimum_staff' => 2,
            'role_requirements' => [],
            'allow_overstaffing' => true,
            'is_active' => true,
        ]);

        ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Uncovered',
            'site_id' => $site->id,
            'triggered_at' => Carbon::parse('2026-04-06 10:05:00'),
            'status' => 'open',
        ]);

        $report = $this->service->build([
            'date_from' => '2026-04-06',
            'date_to' => '2026-04-06',
            'site_id' => $site->id,
        ]);

        $coverage = $report['coverage_gap_report'];

        $this->assertSame(1, $coverage['gap_window_count']);
        $this->assertSame(2, $coverage['rows'][0]['required_staff']);
        $this->assertSame(1, $coverage['rows'][0]['assigned_staff']);
        $this->assertSame(1, $coverage['rows'][0]['deficit']);
        $this->assertSame(1, $coverage['unresolved_uncovered_count']);
    }

    public function test_reconciliation_report_includes_blocked_review_and_backlog_rows(): void
    {
        [$site, $client, $serviceContext] = $this->makeSiteContext();
        $staff = User::factory()->create(['name' => 'Morgan Staff']);

        Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-06',
            'reconciliation_status' => 'blocked',
            'reconciliation_severity' => 'high',
            'reconciliation_summary' => 'Blocking mismatch.',
        ]);

        Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-07',
            'reconciliation_status' => 'review',
            'reconciliation_severity' => 'medium',
            'reconciliation_summary' => 'Needs review.',
        ]);

        Timesheet::factory()->approved()->create([
            'shift_id' => null,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-08',
            'exported_to_payroll_at' => null,
            'payroll_reference' => null,
        ]);

        $completedShift = $this->makeShift($site, $client, $serviceContext, $staff, [
            'status' => 'completed',
            'starts_at' => Carbon::parse('2026-04-09 08:00:00'),
            'ends_at' => Carbon::parse('2026-04-09 16:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-09 08:01:00'),
            'actual_ends_at' => Carbon::parse('2026-04-09 16:05:00'),
        ]);

        HrAttendanceSession::create([
            'user_id' => $staff->id,
            'shift_id' => $completedShift->id,
            'site_id' => $site->id,
            'clock_in_at' => Carbon::parse('2026-04-10 09:00:00'),
            'clock_out_at' => Carbon::parse('2026-04-10 17:00:00'),
            'break_minutes' => 30,
            'status' => 'closed',
            'source' => 'test',
        ]);

        $report = $this->service->build([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'site_id' => $site->id,
        ]);

        $reconciliation = $report['timesheet_reconciliation_report'];

        $this->assertSame(1, $reconciliation['blocked_count']);
        $this->assertSame(1, $reconciliation['review_count']);
        $this->assertSame(1, $reconciliation['completed_shift_without_timesheet_count']);
        $this->assertSame(1, $reconciliation['attendance_without_timesheet_count']);
        $this->assertSame(1, $reconciliation['approved_not_exported_count']);
    }

    public function test_variance_and_risk_summary_are_driven_by_real_shift_data(): void
    {
        [$site, $client, $serviceContext] = $this->makeSiteContext();
        $staff = User::factory()->create(['name' => 'Casey Staff']);

        $shift = $this->makeShift($site, $client, $serviceContext, $staff, [
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-06 09:25:00'),
            'actual_ends_at' => Carbon::parse('2026-04-06 17:35:00'),
            'status' => 'completed',
        ]);

        ShiftSignal::create([
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $staff->id,
            'signal_type' => 'shift_no_show',
            'severity_hint' => 'medium',
            'occurred_at' => Carbon::parse('2026-04-06 09:20:00'),
            'idempotency_key' => hash('sha256', 'no-show-risk'),
        ]);

        ShiftSignal::create([
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $staff->id,
            'signal_type' => 'shift_late_start',
            'severity_hint' => 'medium',
            'occurred_at' => Carbon::parse('2026-04-06 09:25:00'),
            'idempotency_key' => hash('sha256', 'late-start-risk'),
        ]);

        Timesheet::factory()->submitted()->create([
            'shift_id' => null,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-05',
            'submitted_at' => Carbon::parse('2026-04-02 10:00:00'),
        ]);

        Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-06',
            'reconciliation_status' => 'blocked',
            'reconciliation_severity' => 'high',
            'reconciliation_summary' => 'Blocking issue.',
        ]);

        $report = $this->service->build([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'site_id' => $site->id,
            'staff_id' => $staff->id,
        ]);

        $variance = $report['attendance_variance_report'];
        $risk = $report['risk_summary'];

        $this->assertSame(25.0, $variance['avg_start_variance_minutes']);
        $this->assertSame(35.0, $variance['avg_end_variance_minutes']);
        $this->assertSame(1, $variance['no_show_count']);
        $this->assertSame(1, $variance['late_start_count']);
        $this->assertSame(1, $risk['high_risk_reconciliation_count']);
        $this->assertSame(1, $risk['overdue_timesheet_approvals_count']);
        $this->assertSame(1, $risk['frequent_start_anomaly_staff_count']);
    }

    public function test_date_and_site_filters_exclude_out_of_scope_rows(): void
    {
        [$siteA, $clientA, $serviceContextA] = $this->makeSiteContext();
        [$siteB, $clientB, $serviceContextB] = $this->makeSiteContext();
        $staff = User::factory()->create();

        $this->makeShift($siteA, $clientA, $serviceContextA, $staff, [
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
        ]);

        $this->makeShift($siteB, $clientB, $serviceContextB, $staff, [
            'starts_at' => Carbon::parse('2026-05-01 09:00:00'),
            'ends_at' => Carbon::parse('2026-05-01 17:00:00'),
        ]);

        $report = $this->service->build([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'site_id' => $siteA->id,
        ]);

        $this->assertSame(1, $report['staff_utilisation']['total_shifts']);
        $this->assertSame($siteA->name, $report['attendance_variance_report']['shift_rows'][0]['site_name']);
    }

    protected function makeSiteContext(): array
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $serviceContext = ServiceContext::factory()->create();

        return [$site, $client, $serviceContext];
    }

    protected function makeShift(Site $site, Client $client, ServiceContext $serviceContext, User $staff, array $attributes = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'created_by' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ], $attributes));
    }
}
