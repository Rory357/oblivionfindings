<?php

use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\AuditLog;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralFixtures.php';
require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

function referralReportFilters(array $input = []): array
{
    return app(ReferralReports::class)->filters(['from' => '2026-10-16', 'to' => '2026-10-16', ...$input]);
}

// These cases check the arithmetic on deliberately tiny cohorts, so they run with the small-group
// rule off (1). ReferralReportPrivacyTest covers the rule at its default of 5.
beforeEach(fn () => config(['referral-reports.small_group_threshold' => 1]));

it('reports actual commencement separately from a scheduled approval using the real start workflow', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $f = referralStartFixture();
    handoverCanonicalPermissions($f['manager'], ['referrals.reports.view']);
    $reports = app(ReferralReports::class);
    $filters = referralReportFilters(['scope' => 'current']);
    $starts = app(ReferralServiceCommencement::class);
    $starts->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $scheduled = $reports->build($f['manager']->fresh(), $filters, CarbonImmutable::now('UTC'));
    expect(array_column($scheduled['metrics'], 'value', 'key'))->toMatchArray(['converted' => 1, 'commenced' => 0]);
    $this->travel(1)->minutes();
    $starts->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput());
    $actual = $reports->build($f['manager']->fresh(), $filters, CarbonImmutable::now('UTC'));
    expect(array_column($actual['metrics'], 'value', 'key'))->toMatchArray(['converted' => 1, 'commenced' => 1]);
});

it('preserves configured pathway labels from each case publication when later settings rename a pathway', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view', 'funding.view']);
    $referral = referralTestRecord($site);
    $configuration = ReferralSettings::defaults();
    $configuration['pathways'][0]['label'] = 'Reviewed local navigation pathway';
    $later = ReferralSettingsVersion::create(['version' => 2, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    foreach ([$referral->settings_version_id, $later->id] as $versionId) {
        ReferralFundingCase::create(['referral_id' => $referral->id, 'navigator_id' => $actor->id, 'settings_version_id' => $versionId, 'pathway' => 'dss_nasc', 'status' => 'exploring', 'personal_income' => false, 'details' => []]);
    }
    $report = app(ReferralReports::class)->build($actor, referralReportFilters(), CarbonImmutable::now('UTC'));
    expect(array_column($report['funding']['by_pathway'], 'count', 'label'))->toBe(['DSS / NASC' => 1, 'Reviewed local navigation pathway' => 1]);
    expect(array_unique(array_column($report['funding']['by_pathway'], 'key')))->toHaveCount(2);
});

it('uses inclusive NZ received days and current outcomes without treating uncontacted referrals as failures', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view']);
    $atStart = referralTestRecord($site);
    $atStart->forceFill(['created_at' => '2026-10-15 11:00:00', 'first_human_contact_at' => '2026-10-15 13:00:00', 'first_contact_attempt_at' => '2026-10-15 12:00:00'])->save();
    referralTestRecord($site)->forceFill(['created_at' => '2026-10-15 10:59:59'])->save();
    referralTestRecord($site)->forceFill(['created_at' => '2026-10-16 11:00:00'])->save();
    referralTestRecord($site);
    $report = app(ReferralReports::class)->build($actor, referralReportFilters(), CarbonImmutable::now('UTC'));
    expect(array_column($report['metrics'], 'value', 'key'))->toMatchArray(['received' => 2, 'contacted' => 1, 'converted' => 0, 'commenced' => 0]);
    expect($report['trend'])->toBe([['date' => '2026-10-16', 'received' => 2, 'contacted' => 1]])
        ->and($report['delays']['contact']['mean_hours'])->toBe(2.0)->and($report['delays']['contact']['count'])->toBe(1);
});

it('scopes report records and funding independently and denies departed staff and report-only grants', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned', 'reports.view']);
    $referral = referralTestRecord($site, ['owner_id' => $actor->id]);
    foreach ([[$site, ['restricted' => true, 'owner_id' => $actor->id]], [$other, ['owner_id' => $actor->id]], [null, ['owner_id' => $actor->id]], [$site, []]] as [$scope, $overrides]) {
        referralTestRecord($scope, $overrides);
    }
    ReferralFundingCase::create(['referral_id' => $referral->id, 'navigator_id' => $actor->id, 'settings_version_id' => $referral->settings_version_id, 'pathway' => 'private', 'status' => 'exploring', 'personal_income' => false, 'details' => ['secret' => 'Do not project']]);
    $this->actingAs($actor)->get('/operations/referrals/reports?from=2026-10-16&to=2026-10-16')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('report.metrics.0.value', 1)->where('report.funding', null)->where('records.total', 1));
    $onlyReports = referralTestUser($site, ['reports.view']);
    $this->actingAs($onlyReports)->get('/operations/referrals/reports')->assertForbidden();
    $actor->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($actor->fresh())->get('/operations/referrals/reports')->assertForbidden();
});

it('keeps older unresolved and converted open work in current workload and reconciles metric drill through', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view']);
    $old = referralTestRecord($site, ['next_due_at' => now()->subHour()]);
    $old->forceFill(['created_at' => now()->subHours(36)])->save();
    $converted = referralTestRecord($site, ['stage' => 'converted', 'converted_at' => now()->subDay(), 'next_due_at' => null]);
    ReferralWorkItem::create(['referral_id' => $converted->id, 'owner_id' => $actor->id, 'kind' => 'call', 'audience' => 'intake', 'status' => 'open', 'details' => ['title' => 'Follow-up'], 'due_at' => now()->subHour()]);
    referralTestRecord($site, ['stage' => 'converted', 'converted_at' => now()->subDay()]);
    referralTestRecord($site, ['stage' => 'closed', 'next_due_at' => now()->subDay()]);
    $service = app(ReferralReports::class);
    $filters = referralReportFilters(['scope' => 'current']);
    $report = $service->build($actor, $filters, CarbonImmutable::now('UTC'));
    $metrics = array_column($report['metrics'], 'value', 'key');
    expect($metrics)->toMatchArray(['received' => 2, 'overdue' => 2, 'unassigned' => 1, 'converted' => 1, 'commenced' => 0]);
    foreach ($metrics as $key => $value) {
        expect($service->metric($service->query($actor, $filters), $actor, $key, CarbonImmutable::now('UTC'))->count())->toBe($value);
    }
    expect($report['trend'])->toBe([]);
});

it('distinguishes capacity holds and personal income assistance without projecting narratives', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view', 'funding.view']);
    $referral = referralTestRecord($site, ['stage' => 'on_hold', 'hold_reason' => 'capacity_waitlist']);
    referralTestRecord($site, ['stage' => 'on_hold', 'hold_reason' => 'awaiting_person']);
    foreach ([false, true] as $income) {
        ReferralFundingCase::create(['referral_id' => $referral->id, 'navigator_id' => $actor->id, 'settings_version_id' => $referral->settings_version_id, 'pathway' => $income ? 'personal_income' : 'dss_nasc', 'status' => 'exploring', 'personal_income' => $income, 'details' => ['secret' => 'Private financial circumstances']]);
    }
    $report = app(ReferralReports::class)->build($actor, referralReportFilters(), CarbonImmutable::now('UTC'));
    expect(array_column($report['metrics'], 'value', 'key')['capacity_waitlist'])->toBe(1)
        ->and($report['funding'])->toMatchArray(['cases' => 2, 'provider_cases' => 1, 'income_cases' => 1]);
    expect(json_encode($report))->not->toContain('Private financial circumstances');
});

it('exports only authorized fields with independent permission audit and formula protection', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny', 'reports.view']);
    $exporter = referralTestUser($site, ['viewAny', 'reports.view', 'reports.export']);
    referralTestRecord($site, ['identity' => ['first_name' => '=HYPERLINK("evil")'], 'assessment' => ['notes' => 'Never export assessment']]);
    $input = ['from' => '2026-10-16', 'to' => '2026-10-16', 'format' => 'records'];
    $this->actingAs($reader)->postJson('/operations/referrals/reports/export', $input)->assertForbidden();
    $response = $this->actingAs($exporter)->postJson('/operations/referrals/reports/export', $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->toContain("'=HYPERLINK")->not->toContain('Never export assessment');
    expect(AuditLog::where('action', 'referrals.report_export_prepared')->count())->toBe(1);
    expect(AuditLog::where('action', 'referrals.report_export_prepared')->first()->meta)->toMatchArray(['owner' => 'all', 'source' => '', 'stage' => '']);
    $summary = $this->postJson('/operations/referrals/reports/export', [...$input, 'format' => 'summary'])->assertOk();
    expect($summary->getContent())->toContain('Human contact recorded')->not->toContain('provider_cases')->not->toContain('=HYPERLINK');
    $this->getJson('/operations/referrals/reports?from=2026-10-17&to=2026-10-16')->assertUnprocessable();
});
