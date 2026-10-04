<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPayrollSourceUse;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\HrPayrollAccessService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\HrLiveGapCloseoutPayrollSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->allowedSite = Site::factory()->create();
    $this->hiddenSite = Site::factory()->create();
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
});

function liveGapPayrollProfile(string $marker, ?Site $site): HrEmployeeProfile
{
    $employee = User::factory()->create([
        'name' => $marker.' Payroll Worker',
        'email' => strtolower($marker).'.payroll@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    return HrEmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employee_number' => $marker.'-PAY',
        'work_email' => $employee->email,
        'position_title' => 'Payroll Smoke Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
    ]);
}

function liveGapPayrollCounts(): array
{
    return [
        'runs' => HrPayrollRun::query()->count(),
        'items' => HrPayrollRunItem::query()->count(),
        'payslips' => HrPayslip::query()->count(),
        'source_uses' => HrPayrollSourceUse::query()->count(),
        'audit_logs' => AuditLog::query()->count(),
        'role_users' => DB::table('role_user')->count(),
        'permission_overrides' => DB::table('permission_user')->count(),
    ];
}

it('seeds a coherent marked historical payroll item and preserves unrelated payroll on replay', function () {
    $unrelatedProfile = liveGapPayrollProfile('UNRELATED-HR-PAY', $this->hiddenSite);
    $seeder = app(HrLiveGapCloseoutPayrollSeeder::class);
    $unrelated = $seeder->seedPayroll('UNRELATED-HR-PAY', $this->admin, $unrelatedProfile);
    $unrelatedBefore = collect($unrelated)->map(fn ($record) => $record->refresh()->getRawOriginal())->all();

    $marker = 'CODEX-LIVE-HR-GAPS-PAYROLL';
    $profile = liveGapPayrollProfile($marker, $this->allowedSite);
    $profileBefore = $profile->refresh()->getRawOriginal();
    $employeeBefore = $profile->user->refresh()->getRawOriginal();
    $seeded = $seeder->seedPayroll($marker, $this->admin, $profile);
    ['run' => $run, 'item' => $item, 'payslip' => $payslip] = $seeded;

    expect($run->items()->pluck('user_id')->all())->toBe([$profile->user_id])
        ->and($run->source_provenance_status)->toBe('legacy_no_paid_leave')
        ->and($run->status)->toBe('locked')
        ->and($run->total_staff)->toBe(1)
        ->and($run->total_hours)->toBe('80.00')
        ->and($run->total_gross)->toBe('2400.00')
        ->and($item->regular_hours)->toBe($run->total_hours)
        ->and($item->base_hourly_rate)->toBe('30.00')
        ->and($item->gross_pay)->toBe($run->total_gross)
        ->and($item->leave_hours)->toBe('0.00')
        ->and($item->leave_pay)->toBe('0.00')
        ->and($item->timesheet_ids)->toBe([])
        ->and($run->sourceUses()->count())->toBe(0)
        ->and($payslip->user_id)->toBe($profile->user_id)
        ->and($payslip->employee_profile_id)->toBe($profile->id)
        ->and($payslip->regular_hours)->toBe($item->regular_hours)
        ->and($payslip->gross_pay)->toBe($item->gross_pay)
        ->and($payslip->pay_period_start->toDateString())->toBe($run->period_start->toDateString())
        ->and($payslip->pay_period_end->toDateString())->toBe($run->period_end->toDateString());

    $beforeReplay = collect($seeded)->map(fn ($record) => $record->refresh()->getRawOriginal())->all();
    $countsBeforeReplay = liveGapPayrollCounts();
    $this->travel(1)->days();
    $replayed = $seeder->seedPayroll($marker, $this->admin, $profile);

    expect(collect($replayed)->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($beforeReplay)
        ->and(liveGapPayrollCounts())->toBe($countsBeforeReplay)
        ->and(collect($unrelated)->map(fn ($record) => $record->refresh()->getRawOriginal())->all())->toBe($unrelatedBefore)
        ->and($profile->refresh()->getRawOriginal())->toBe($profileBefore)
        ->and($profile->user->refresh()->getRawOriginal())->toBe($employeeBefore);
});

it('shows the synthetic run only with complete staff Site provenance and the payroll capability', function () {
    $viewerProfile = liveGapPayrollProfile('PAYROLL-SITE-VIEWER', $this->allowedSite);
    $viewer = $viewerProfile->user;
    $viewPermission = Permission::query()->where('key', 'hr.payroll.view')->firstOrFail();
    $viewer->permissionOverrides()->syncWithoutDetaching([$viewPermission->id => ['allowed' => true]]);
    expect($viewer->canDo('hr.payroll.view'))->toBeTrue()
        ->and($viewer->canDo('hr.employees.viewAllSites'))->toBeFalse();

    $seeder = app(HrLiveGapCloseoutPayrollSeeder::class);
    $allowed = $seeder->seedPayroll('PAYROLL-ALLOWED', $this->admin, liveGapPayrollProfile('PAYROLL-ALLOWED', $this->allowedSite));
    $hidden = $seeder->seedPayroll('PAYROLL-HIDDEN', $this->admin, liveGapPayrollProfile('PAYROLL-HIDDEN', $this->hiddenSite));
    $unproven = $seeder->seedPayroll('PAYROLL-UNPROVEN', $this->admin, liveGapPayrollProfile('PAYROLL-UNPROVEN', null));
    $mixed = $seeder->seedPayroll('PAYROLL-MIXED', $this->admin, liveGapPayrollProfile('PAYROLL-MIXED', $this->allowedSite));
    HrPayrollRunItem::query()->create(['payroll_run_id' => $mixed['run']->id, 'user_id' => $hidden['item']->user_id]);
    $empty = HrPayrollRun::factory()->create(['status' => 'locked']);
    $snapshots = collect([$allowed['run'], $hidden['run'], $unproven['run'], $mixed['run'], $empty])
        ->mapWithKeys(fn ($run) => [$run->id => $run->refresh()->getRawOriginal()])->all();
    $countsBeforeReads = liveGapPayrollCounts();

    $response = $this->actingAs($viewer)->get(route('hr.payroll.index'))->assertOk();
    expect(collect($response->inertiaProps('runs.data'))->pluck('id')->all())->toBe([$allowed['run']->id])
        ->and($response->inertiaProps('runs.data.0.items_count'))->toBe(1)
        ->and($response->inertiaProps('statusCounts.total'))->toBe(1);

    $access = app(HrPayrollAccessService::class);
    expect($access->payrollRun($viewer, $allowed['run'])->id)->toBe($allowed['run']->id);
    foreach ([$hidden['run'], $unproven['run'], $mixed['run'], $empty] as $denied) {
        expect(fn () => $access->payrollRun($viewer, $denied))->toThrow(ModelNotFoundException::class);
    }

    $this->actingAs($allowed['item']->user)->get(route('hr.payroll.index'))->assertForbidden();
    expect(liveGapPayrollCounts())->toBe($countsBeforeReads)
        ->and(HrPayrollRun::query()->whereIn('id', array_keys($snapshots))->get()
            ->mapWithKeys(fn ($run) => [$run->id => $run->getRawOriginal()])->all())->toBe($snapshots);
});

it('refuses to seed payroll for an employee outside the exact synthetic marker without effects', function () {
    $profile = liveGapPayrollProfile('UNRELATED-EMPLOYEE', $this->allowedSite);
    $profileBefore = $profile->refresh()->getRawOriginal();
    $employeeBefore = $profile->user->refresh()->getRawOriginal();
    $countsBefore = liveGapPayrollCounts();

    expect(fn () => app(HrLiveGapCloseoutPayrollSeeder::class)
        ->seedPayroll('CODEX-LIVE-HR-GAPS-OTHER', $this->admin, $profile))
        ->toThrow(InvalidArgumentException::class, 'The marked synthetic payroll employee is required.')
        ->and(liveGapPayrollCounts())->toBe($countsBefore)
        ->and($profile->refresh()->getRawOriginal())->toBe($profileBefore)
        ->and($profile->user->refresh()->getRawOriginal())->toBe($employeeBefore);
});
