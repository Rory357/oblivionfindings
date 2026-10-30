<?php

use App\Domain\Referrals\Services\ReferralReportPrivacy;
use App\Domain\Referrals\Services\ReferralReports;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

/** 9 phone referrals in triage, 6 email referrals that are new and 3 website referrals in assessment. */
function referralPrivacyCohort(Site $site): void
{
    foreach ([['triage', 'phone', 9], ['new', 'email', 6], ['assessment', 'website', 3]] as [$stage, $source, $count]) {
        for ($i = 0; $i < $count; $i++) {
            referralTestRecord($site, ['stage' => $stage, 'source' => $source]);
        }
    }
}

it('hides small groups and the next-smallest so no hidden group can be worked out from the total', function () {
    $privacy = app(ReferralReportPrivacy::class);
    $rows = $privacy->groups([['key' => 'a', 'count' => 12], ['key' => 'b', 'count' => 3], ['key' => 'c', 'count' => 7], ['key' => 'd', 'count' => 0]]);
    expect(array_column($rows, 'count', 'key'))->toBe(['a' => 12, 'b' => null, 'c' => null, 'd' => 0])
        ->and(array_column($rows, 'suppressed', 'key'))->toBe(['b' => 'small', 'c' => 'paired']);

    // With a total of 36, hiding 1 and 5 alone would pin the small group to 1, so 30 is hidden too.
    expect(array_column($privacy->groups([['key' => 'x', 'count' => 1], ['key' => 'y', 'count' => 5], ['key' => 'z', 'count' => 30]]), 'suppressed', 'key'))
        ->toBe(['x' => 'small', 'y' => 'paired', 'z' => 'paired']);
    // Two small groups that already add up to the threshold need no partner.
    expect(array_column($privacy->groups([['key' => 'p', 'count' => 2], ['key' => 'q', 'count' => 3], ['key' => 'r', 'count' => 9]]), 'count', 'key'))
        ->toBe(['p' => null, 'q' => null, 'r' => 9]);
    // Nothing small: the rows are returned unchanged, without flags, and zero stays zero.
    expect($privacy->groups([['key' => 'm', 'count' => 6], ['key' => 'n', 'count' => 0]]))->toBe([['key' => 'm', 'count' => 6], ['key' => 'n', 'count' => 0]]);
});

it('combines hidden reported regions so a typed place name is never shown for a small group', function () {
    $report = app(ReferralReportPrivacy::class)->protect([
        'metrics' => [], 'trend' => [], 'delays' => [], 'funding' => null, 'definitions' => [],
        'groups' => ['region' => [
            ['key' => 'not_recorded', 'label' => 'Not recorded', 'count' => 12],
            ['key' => str_repeat('a', 64), 'label' => 'Auckland', 'count' => 6],
            ['key' => str_repeat('b', 64), 'label' => 'Kaitaia', 'count' => 2],
            ['key' => str_repeat('c', 64), 'label' => '12 Example Road, Rawene', 'count' => 3],
        ]],
    ]);
    expect(array_column($report['groups']['region'], 'count', 'label'))->toBe(['Not recorded' => 12, 'Auckland' => 6, 'Other reported regions' => 5])
        ->and(json_encode($report))->not->toContain('Kaitaia')->not->toContain('Example Road');
});

it('hides small groups in the report for every viewer while keeping the total exact', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view']);
    referralPrivacyCohort($site);
    $filters = app(ReferralReports::class)->filters(['from' => '2026-10-16', 'to' => '2026-10-16']);

    $report = app(ReferralReports::class)->build($actor, $filters, CarbonImmutable::now('UTC'));
    expect(array_column($report['metrics'], 'value', 'key'))->toMatchArray(['received' => 18, 'contacted' => 0, 'converted' => 0])
        ->and(array_column($report['groups']['stage'], 'count', 'key'))->toBe(['triage' => 9, 'new' => null, 'assessment' => null])
        ->and(array_column($report['groups']['stage'], 'suppressed', 'key'))->toBe(['new' => 'paired', 'assessment' => 'small'])
        ->and(array_column($report['groups']['source'], 'count', 'key'))->toBe(['phone' => 9, 'email' => null, 'website' => null])
        ->and($report['trend'])->toBe([['date' => '2026-10-16', 'received' => 18, 'contacted' => 0]])
        ->and($report['privacy'])->toMatchArray(['threshold' => 5, 'label' => 'Fewer than 5', 'paired_label' => 'Hidden', 'active' => true]);

    // A hidden count is null with a reason, never 0, and the numbers are not left anywhere in the payload.
    $small = collect($report['groups']['stage'])->firstWhere('key', 'assessment');
    expect($small['count'])->toBeNull()->and($small)->not->toHaveKey('total');

    // The privacy rule is a config setting: 1 turns it off and shows exact numbers.
    config(['referral-reports.small_group_threshold' => 1]);
    $exact = app(ReferralReports::class)->build($actor, $filters, CarbonImmutable::now('UTC'));
    expect(array_column($exact['groups']['stage'], 'count', 'key'))->toBe(['triage' => 9, 'new' => 6, 'assessment' => 3])
        ->and($exact['privacy']['active'])->toBeFalse();
});

it('hides a small total and its measures on the page while the record list follows record access', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view']);
    referralTestRecord($site);
    referralTestRecord($site, ['first_human_contact_at' => now()->addMinute(), 'first_contact_attempt_at' => now()->addMinute()]);
    $this->travel(2)->minutes();

    $this->actingAs($actor)->get('/operations/referrals/reports?from=2026-10-16&to=2026-10-16')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.metrics.0.key', 'received')->where('report.metrics.0.value', null)->where('report.metrics.0.suppressed', 'small')
            ->where('report.delays.contact.count', null)->where('report.delays.contact.mean_hours', null)
            ->where('report.trend.0.received', null)->where('report.trend.0.received_suppressed', 'small')
            ->where('report.privacy.label', 'Fewer than 5')
            ->where('records.total', 2));
});

it('applies the same rule to the summary export and leaves the records export at record access', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00+13:00')->utc());
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'reports.view', 'reports.export']);
    referralPrivacyCohort($site);
    $input = ['from' => '2026-10-16', 'to' => '2026-10-16'];

    $summary = $this->actingAs($actor)->postJson('/operations/referrals/reports/export', [...$input, 'format' => 'summary'])->assertOk()->getContent();
    expect($summary)->toContain('triage,9')
        ->toContain('assessment,"Fewer than 5"')
        ->toContain('new,Hidden')
        ->toContain('website,"Fewer than 5"')
        ->toContain('Small groups')
        ->not->toContain('assessment,3')
        ->not->toContain('new,6')
        ->not->toContain('website,3');

    $records = $this->postJson('/operations/referrals/reports/export', [...$input, 'format' => 'records'])->assertOk()->getContent();
    expect(substr_count($records, 'website'))->toBe(3);
});
