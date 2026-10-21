<?php

use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Services\DonorFundService;
use App\Domain\Governance\Models\AuditEvidencePack;
use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\EvidenceLibrary;
use App\Domain\Governance\Models\RiskAcceptance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\LeaveReportService;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Http\Controllers\Hr\ComplianceCalendarController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\TimelineController;
use App\Models\Asset;
use App\Models\ClientPersonalAsset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;

/*
 * Carbon 3 made diffInDays() signed (and a float). `$future->diffInDays(now())
 * <= N` is negative for any future date, so every dated record read as
 * "expiring soon"; `$later->diffInDays($earlier) > N` never passed; and
 * `diffInDays() === 1` never matched. These pin the corrected windows.
 */

function signedDiffWindowCases(): array
{
    return [
        'in ten days' => [10, true],
        'in six months' => [182, false],
        'yesterday' => [-1, false],
    ];
}

it('only flags governance and HR records expiring inside their window', function (int $offsetDays, bool $expected) {
    $date = now()->addDays($offsetDays);

    expect((new EvidenceLibrary)->forceFill(['valid_until' => $date])->isExpiring())->toBe($expected)
        ->and((new ComplianceEvidence)->forceFill(['valid_until' => $date])->isExpiringSoon())->toBe($expected)
        ->and((new AuditEvidencePack)->forceFill(['retention_until' => $date, 'deleted_after_retention' => false])->isExpiringSoon())->toBe($expected)
        ->and((new RiskAcceptance)->forceFill(['expires_at' => $date])->isExpiringSoon())->toBe($expected)
        ->and((new ClientPersonalAsset)->forceFill(['warranty_expires_at' => $date])->isWarrantyExpiringSoon())->toBe($expected)
        ->and((new StaffBackgroundCheck)->forceFill(['expires_at' => $date])->isExpiringSoon())->toBe($expected);
})->with(signedDiffWindowCases());

it('counts donor funds ending in the next 90 days, not every dated fund', function () {
    foreach (['SOON' => 30, 'LATER' => 365, 'ENDED' => -10] as $code => $offset) {
        FinDonorFund::query()->forceCreate([
            'organization_id' => 1,
            'fund_code' => $code,
            'fund_name' => "Fund {$code}",
            'fund_type' => 'grant',
            'end_date' => now()->addDays($offset)->toDateString(),
            'status' => 'active',
        ]);
    }

    expect(app(DonorFundService::class)->getFundsSummary(1)['expiring_soon'])->toBe(1);
});

it('counts asset warranties expiring in the next 30 days on the condition report', function () {
    $viewer = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create(['name' => 'asset_condition_'.$viewer->id, 'label' => 'Asset condition', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'reports.sites.view'],
        ['description' => 'reports.sites.view', 'group' => 'test', 'module' => 'Test'],
    );
    $role->permissions()->sync([$permission->id]);
    $viewer->roles()->sync([$role->id]);

    Asset::factory()->create(['warranty_expires_at' => now()->addDays(10)]);
    Asset::factory()->create(['warranty_expires_at' => now()->addYear()]);
    Asset::factory()->create(['warranty_expires_at' => now()->subDays(5)]);

    $this->actingAs($viewer)
        ->get('/sites/reports/asset-condition')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total_assets', 3)
            ->where('summary.warranty_expiring_soon', 1)
            ->where('summary.warranty_expired', 1));
});

it('marks HR compliance renewals critical only when expired or within 30 days', function () {
    $urgency = new ReflectionMethod(HrCalendarAggregator::class, 'complianceUrgency');
    $now = now();

    expect($urgency->invoke(null, $now->copy()->addDays(10), $now))->toBe('critical')
        ->and($urgency->invoke(null, $now->copy()->subDay(), $now))->toBe('critical')
        ->and($urgency->invoke(null, $now->copy()->addMonths(6), $now))->toBe('warning');

    $controller = app(ComplianceCalendarController::class);
    $colour = new ReflectionMethod($controller, 'getEventColor');

    expect($colour->invoke($controller, $now->copy()->subDay(), $now))->toBe('#ef4444')
        ->and($colour->invoke($controller, $now->copy()->addDays(10), $now))->toBe('#f97316')
        ->and($colour->invoke($controller, $now->copy()->addMonths(6), $now))->toBe('#3b82f6');
});

it('caps summary and timeline ranges at 60 days', function (string $controllerClass) {
    $controller = app($controllerClass);
    $parse = new ReflectionMethod($controller, 'parseRange');

    $wide = $parse->invoke($controller, Request::create('/', 'GET', ['from' => '2026-01-01', 'to' => '2026-06-01']));
    $narrow = $parse->invoke($controller, Request::create('/', 'GET', ['from' => '2026-01-01', 'to' => '2026-01-20']));

    expect($wide['to']->toDateString())->toBe('2026-03-02')
        ->and($narrow['to']->toDateString())->toBe('2026-01-20');
})->with([SummaryController::class, TimelineController::class]);

it('starts a new Bradford spell only after a gap of more than one day', function () {
    $staff = User::factory()->create();
    $sick = fn (int $startDay, int $endDay) => HrLeaveRequest::query()->create([
        'user_id' => $staff->id,
        'leave_type' => 'sick',
        'status' => 'approved',
        'starts_at' => now()->startOfYear()->addDays($startDay),
        'ends_at' => now()->startOfYear()->addDays($endDay),
        'hours_requested' => 8,
    ]);
    $sick(5, 6);
    $sick(7, 8);   // the next day: same spell
    $sick(20, 21); // after a gap: a new spell

    $row = collect(app(LeaveReportService::class)->getBradfordFactor(now()->year)['employees'])
        ->firstWhere('user_id', $staff->id);

    expect($row['spells'])->toBe(2);
});

it('counts consecutive worked days for the wellbeing streak', function () {
    $streak = new ReflectionMethod(WellbeingIndicatorService::class, 'calculateConsecutiveDays');
    $days = collect(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-05', '2026-10-06'])
        ->map(fn (string $date) => ['work_date' => $date]);

    expect($streak->invoke(app(WellbeingIndicatorService::class), $days))->toBe(3);
});
