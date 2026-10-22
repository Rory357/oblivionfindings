<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Http\Controllers\ReferralReportController;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralReportAges;
use App\Domain\Referrals\Services\ReferralReportRegion;
use App\Domain\Referrals\Services\ReferralReports;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$cutoff = CarbonImmutable::parse('2026-10-18T03:00:00Z');
foreach ([0 => '0_6', 604799 => '0_6', 604800 => '7_13', 1209599 => '7_13', 1209600 => '14_29', 2591999 => '14_29', 2592000 => '30_plus'] as $seconds => $expected) {
    $check(ReferralReportAges::bucket($cutoff->subSeconds($seconds)->toISOString(), $cutoff) === $expected, 'Exact elapsed boundary '.$seconds);
}
foreach ([null, '', 'yesterday', '2026-02-30T00:00:00Z', '2026-10-19T00:00:00Z', '2026-10-18'] as $value) {
    $check(ReferralReportAges::bucket($value, $cutoff) === 'unknown', 'Missing or invalid date remains unknown: '.json_encode($value));
}
$check(ReferralReportAges::bucket('2026-10-11T16:00:00+13:00', $cutoff) === '7_13', 'Equivalent NZ offset preserves elapsed boundary');
$dstCutoff = CarbonImmutable::parse('2026-10-04T00:00:00+13:00');
$check(ReferralReportAges::bucket('2026-09-27T00:00:00+12:00', $dstCutoff) === '0_6', 'DST calendar week is correctly under seven elapsed days');

DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $grant = fn ($key, $allowed) => $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.'.$key)->sole()->id, ['allowed' => $allowed]);
    $grant('restricted', false);
    $grant('funding.view', true);
    $template = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole();
    $make = function ($at, $stage = 'assessment', $restricted = false) use ($template, $cutoff) {
        $record = $template->replicate(['reference']);
        $record->forceFill(['source' => 'qa_report_age', 'stage' => $stage, 'restricted' => $restricted, 'stage_changed_at' => $at, 'created_at' => $cutoff->subDays(60), 'client_id' => null, 'primary_work_item_id' => null]);
        $record->save();

        return $record;
    };
    $young = $make($cutoff->subDays(1));
    $week = $make($cutoff->subDays(7), 'on_hold');
    $make($cutoff->subDays(14));
    $make($cutoff->subDays(30));
    $make($cutoff->addSecond());
    $make($cutoff->subDays(61));
    $make($cutoff->subDays(30), 'closed');
    $make($cutoff->subDays(30), 'converted');
    $hidden = $make($cutoff->subDays(30), 'assessment', true);
    $young->update(['intake' => [...$young->intake, 'region' => "  Waikato\n Area  "]]);
    $week->update(['intake' => [...$week->intake, 'region' => 'waikato area']]);
    $hidden->update(['intake' => [...$hidden->intake, 'region' => 'Private region must not appear']]);
    $service = app(ReferralReports::class);
    $filters = $service->filters(['from' => '2026-08-01', 'to' => '2026-10-18', 'source' => 'qa_report_age']);
    $report = $service->build($actor->fresh(), $filters, $cutoff);
    $counts = array_column($report['groups']['stage_age'], 'count', 'key');
    $regionKey = ReferralReportRegion::group('Waikato Area')['key'];
    $check(array_column($report['groups']['region'], 'count', 'key')[$regionKey] === 2, 'Reported regions group case and whitespace variants');
    $check(! str_contains(json_encode($report['groups']['region']), 'Private region must not appear'), 'Restricted region labels and counts are excluded');
    $check($service->query($actor->fresh(), [...$filters, 'region' => $regionKey], $cutoff)->count() === 2, 'Region chart and record drill-down agree');
    $check($service->query($actor->fresh(), [...$filters, 'region' => str_repeat('a', 64)], $cutoff)->count() === 0, 'Unknown region key returns no scoped records');
    $check(ReferralReportRegion::group("  \n ") === ['key' => 'not_recorded', 'label' => 'Not recorded'], 'Blank reported region is retained explicitly');
    $check($counts === ['0_6' => 1, '7_13' => 1, '14_29' => 1, '30_plus' => 1, 'unknown' => 2], 'Open stage counts include holds, exclude terminal/restricted and isolate invalid dates');
    foreach ($counts as $key => $count) {
        $check($service->query($actor->fresh(), [...$filters, 'stage_age' => $key], $cutoff)->count() === $count, 'SQL drill-down matches stage bucket '.$key);
    }
    $case = fn ($record, $income, $status, $at) => ReferralFundingCase::create(['referral_id' => $record->id, 'settings_version_id' => $record->settings_version_id, 'navigator_id' => $actor->id, 'pathway' => $income ? 'personal_income' : 'dss_nasc', 'personal_income' => $income, 'status' => $status, 'details' => ['last_submission' => ['submitted_at' => $at], 'private_note' => 'Do not project this narrative']]);
    $case($young, false, 'submitted', $cutoff->subDays(7)->toISOString());
    $case($young, false, 'awaiting_assessment', $cutoff->subDays(7)->toISOString());
    $case($young, true, 'awaiting_decision', $cutoff->subDays(30)->toISOString());
    $case($week, false, 'submitted', null);
    $case($week, false, 'preparing', $cutoff->subDays(7)->toISOString());
    $case($hidden, false, 'submitted', $cutoff->subDays(7)->toISOString());
    $report = $service->build($actor->fresh(), $filters, $cutoff);
    $check(array_column($report['funding']['pending_age']['provider'], 'count', 'key') === ['0_6' => 0, '7_13' => 2, '14_29' => 0, '30_plus' => 0, 'unknown' => 1], 'Pending provider cases exclude preparation and inaccessible referrals');
    $check(array_column($report['funding']['pending_age']['income'], 'count', 'key')['30_plus'] === 1, 'Personal income remains separate');
    $narrow = [...$filters, 'funding_age' => '7_13', 'funding_kind' => 'provider', 'region' => $regionKey];
    $check($service->query($actor->fresh(), $narrow, $cutoff)->pluck('id')->all() === [$young->id], 'Multiple matching cases open one referral');
    $filtered = $service->build($actor->fresh(), $narrow, $cutoff);
    $check($filtered['funding']['cases'] === 2 && $filtered['funding']['income_cases'] === 0, 'Filtered assistance breakdown counts only matching cases');
    $check(! str_contains(json_encode($filtered), 'Do not project this narrative'), 'No encrypted funding narratives enter report projection');
    $request = Request::create('/operations/referrals/reports/export', 'POST', [...$narrow, 'format' => 'records']);
    $request->setUserResolver(fn () => $actor->fresh());
    CarbonImmutable::setTestNow($cutoff);
    $response = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class));
    $csv = $response->getContent();
    $check(str_contains($csv, $young->reference) && ! str_contains($csv, $week->reference) && str_contains($csv, 'Current stage age'), 'Record export preserves age filters and includes stage age');
    $meta = AuditLog::where('action', 'referrals.report_export_prepared')->latest('id')->firstOrFail()->meta;
    $check($meta['funding_age'] === '7_13' && $meta['funding_kind'] === 'provider', 'Export audit retains exact age filters');
    $check($meta['region'] === $regionKey, 'Export audit retains the reported-region filter');
    $request->merge(['format' => 'summary']);
    $summary = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class))->getContent();
    $check(str_contains($summary, 'provider_cases,2') && str_contains($summary, 'income_cases,0') && str_contains($summary, 'provider pending age'), 'Summary export retains case matching and separate waiting-age sections');
    $week->update(['intake' => [...$week->intake, 'region' => '=HYPERLINK("evil")']]);
    $request->merge([...$filters, 'format' => 'summary']);
    $unsafeLabel = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class))->getContent();
    $check(str_contains($unsafeLabel, "'=HYPERLINK"), 'Reported region labels receive CSV formula protection');
    $grant('funding.view', false);
    $check($service->build($actor->fresh(), $filters, $cutoff)['funding'] === null, 'Funding age data disappears when permission is revoked');
    try {
        $service->query($actor->fresh(), $narrow, $cutoff);
        throw new RuntimeException('Funding filter accepted after revocation');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'Funding filter cannot infer data after permission revocation');
    }
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    CarbonImmutable::setTestNow();
    DB::rollBack();
}
