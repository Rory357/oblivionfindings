<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Http\Controllers\ReferralReportController;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralHistoryReport;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$at = fn ($hour) => CarbonImmutable::parse('2026-10-16T'.$hour.':00:00+13:00')->utc();
$clock = function ($hour) use ($at) {
    Carbon::setTestNow($at($hour));
    CarbonImmutable::setTestNow($at($hour));
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.restricted')->sole()->id, ['allowed' => false]);
    $clock('08');
    $config = ReferralSettingsVersion::create(['version' => 50030, 'state' => 'published', 'configuration' => ReferralSettings::defaults(), 'published_at' => $at('08')->subDay(), 'effective_at' => $at('08')->subDay()]);
    $record = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole()->replicate(['reference']);
    $record->forceFill(['source' => 'qa_history_report', 'settings_version_id' => $config->id, 'owner_id' => $actor->id, 'stage' => 'new', 'hold_reason' => null, 'created_at' => $at('08'), 'stage_changed_at' => $at('08'), 'primary_work_item_id' => null, 'next_due_at' => null]);
    $record->save();
    ReferralEvent::create(['referral_id' => $record->id, 'kind' => 'referral.received', 'audience' => 'intake', 'payload' => ['settings_version_id' => $config->id], 'occurred_at' => $at('08')]);
    $lifecycle = app(ReferralLifecycle::class);
    $next = ['reason' => 'Fictional historical workflow check', 'next_action' => 'Review synthetic case', 'next_due_at' => $at('17')->addDays(4)->toISOString()];
    $move = fn ($stage) => $lifecycle->move($actor->fresh(), $record->id, $record->fresh()->lock_version, [...$next, 'stage' => $stage]);
    $hold = fn ($reason) => $lifecycle->hold($actor->fresh(), $record->id, $record->fresh()->lock_version, [...$next, 'hold_reason' => $reason]);
    $close = fn () => $lifecycle->close($actor->fresh(), $record->id, $record->fresh()->lock_version, ['closure_reason' => 'other', 'reason' => 'Fictional closure check', 'person_informed' => false, 'communication_note' => 'Synthetic only; no communication', 'cancel_open_work' => false]);
    $clock('09');
    $move('triage');
    $clock('10');
    $move('assessment');
    $hold('capacity_waitlist');
    $clock('11');
    $hold('awaiting_evidence');
    $clock('12');
    $hold(null);
    $move('triage');
    $clock('13');
    $close();
    $clock('14');
    $lifecycle->reopen($actor->fresh(), $record->id, $record->fresh()->lock_version, $next);
    $hold('capacity_waitlist');
    $clock('15');
    $move('assessment');
    $clock('16');
    $close();
    $clock('17');
    $lifecycle->reopen($actor->fresh(), $record->id, $record->fresh()->lock_version, $next);
    $reports = app(ReferralReports::class);
    $filters = $reports->filters(['from' => '2026-10-16', 'to' => '2026-10-16', 'source' => 'qa_history_report']);
    $r = $reports->build($actor->fresh(), $filters, $at('18'));
    $history = $r['history'];
    $stages = array_column($history['stages'], null, 'key');
    $holds = array_column($history['holds'], null, 'key');
    $check($history['incomplete_referrals'] === 0 && $history['referrals'] === 1, 'Real domain command chain reconciles without invented history');
    $check($history['reopenings'] === 2 && $history['reopened_referrals'] === 1, 'Two reopenings remain one referral, not two new people');
    $check($stages['new']['elapsed']['total_hours'] === 1.0 && $stages['new']['working']['total_hours'] === 0.0, 'Pre-office interval is measured zero working time, not missing');
    $check($stages['triage']['count'] === 3 && $stages['triage']['elapsed']['total_hours'] === 3.0, 'Repeated completed stage visits accumulate and exclude the ongoing reopened visit');
    $check($stages['assessment']['count'] === 2 && $stages['assessment']['elapsed']['total_hours'] === 3.0 && $stages['assessment']['elapsed']['mean_hours'] === 1.5, 'Completed stage mean uses visits rather than unique referrals');
    $check($holds['capacity_waitlist']['count'] === 2 && $holds['capacity_waitlist']['elapsed']['total_hours'] === 3.0, 'Reason change and later closure end separate waiting segments');
    $check($holds['awaiting_evidence']['elapsed']['total_hours'] === 1.0, 'Released evidence wait has its own completed duration');
    $check(! isset($stages['closed']) && ! isset($stages['converted']), 'Terminal periods are not reported as active processing time');
    $check(array_column($r['metrics'], 'value', 'key')['reopened'] === 1 && $reports->metric($reports->query($actor->fresh(), $filters), $actor->fresh(), 'reopened', $at('18'))->count() === 1, 'Reopened metric and record drill-through agree');
    $event = fn ($kind, $hour, $payload = []) => ['kind' => $kind, 'at' => $at($hour), 'payload' => $payload];
    $missing = ReferralHistoryReport::visits($at('08'), [$event('stage.changed', '10', ['previous_stage' => 'triage', 'stage' => 'assessment']), $event('referral.closed', '12', ['previous_stage' => 'assessment'])], $at('18'));
    $check($missing['stages'][0]['from'] === null && $missing['stages'][1]['from']->equalTo($at('10')) && $missing['gaps'] > 0, 'Missing start remains unknown while later proven boundaries can be measured');
    $oldReopen = ReferralHistoryReport::visits($at('08'), [$event('referral.reopened', '10'), $event('stage.changed', '12', ['previous_stage' => 'triage', 'stage' => 'assessment'])], $at('18'));
    $check($oldReopen['reopenings'] === 1 && $oldReopen['stages'][0]['from'] === null && $oldReopen['gaps'] > 0, 'Legacy reopening without its resulting stage does not guess a visit start');
    $sameTime = ReferralHistoryReport::visits($at('08'), [$event('referral.received', '08'), $event('stage.changed', '08', ['previous_stage' => 'new', 'stage' => 'triage'])], $at('18'));
    $check($sameTime['stages'][0]['from']->equalTo($sameTime['stages'][0]['to']), 'Ordered same-time events retain a valid zero-length visit');
    $brokenHold = ReferralHistoryReport::visits($at('08'), [$event('hold.recorded', '09', ['previous_hold_reason' => null, 'hold_reason' => 'capacity_waitlist']), $event('hold.recorded', '10', ['previous_hold_reason' => null, 'hold_reason' => 'awaiting_evidence'])], $at('18'));
    $check($brokenHold['holds'][0]['from'] === null && $brokenHold['gaps'] > 0, 'Contradictory hold boundaries do not produce a trusted elapsed interval');
    $handover = ReferralHistoryReport::visits($at('08'), [$event('support.preparation_opened', '08'), $event('hold.recorded', '09', ['previous_hold_reason' => null, 'hold_reason' => 'capacity_waitlist']), $event('handover.committed', '10', ['previous_stage' => 'assessment'])], $at('18'));
    $check($handover['stage'] === 'converted' && $handover['hold'] === null && $handover['stages'][0]['key'] === 'assessment' && count($handover['holds']) === 1, 'Handover ends both processing and waiting without inventing service commencement');
    $legacyHandover = ReferralHistoryReport::visits($at('08'), [$event('referral.received', '08'), $event('handover.committed', '10')], $at('18'));
    $check($legacyHandover['stages'] === [] && $legacyHandover['gaps'] > 0, 'Legacy handover without its previous stage does not attribute the entire journey to New');
    $invalidDates = ReferralHistoryReport::visits($at('08'), [$event('referral.reopened', '07'), $event('referral.reopened', '19')], $at('18'));
    $check($invalidDates['reopenings'] === 0 && $invalidDates['gaps'] === 2, 'Pre-intake and future events are not counted as valid history');
    $hidden = $record->fresh()->replicate(['reference']);
    $hidden->restricted = true;
    $hidden->save();
    $noHistory = $record->fresh()->replicate(['reference']);
    $noHistory->save();
    $r = $reports->build($actor->fresh(), $filters, $at('18'));
    $check($r['history']['referrals'] === 2 && $r['history']['incomplete_referrals'] === 1, 'Restricted records stay excluded and absent history is explicitly counted');
    $request = Request::create('/operations/referrals/reports/export', 'POST', [...$filters, 'format' => 'summary']);
    $request->setUserResolver(fn () => $actor->fresh());
    $csv = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class))->getContent();
    $check(str_contains($csv, 'stages,assessment,2,3,1.5,2,3,1.5,2,0,0') && ! str_contains($csv, 'Fictional historical workflow check'), 'Summary CSV carries interval denominators and totals without private event narratives');
    $legacyConfiguration = ReferralSettings::defaults();
    unset($legacyConfiguration['business_calendar']['opening_time']);
    $legacy = ReferralSettingsVersion::create(['version' => 50031, 'state' => 'published', 'configuration' => $legacyConfiguration, 'published_at' => $at('08')->subDay(), 'effective_at' => $at('08')->subDay()]);
    $legacyRecord = $record->fresh()->replicate(['reference']);
    $legacyRecord->forceFill(['stage' => 'closed', 'settings_version_id' => $legacy->id, 'created_at' => $at('08')]);
    $legacyRecord->save();
    foreach ([$event('referral.received', '08', ['settings_version_id' => $legacy->id]), $event('referral.closed', '09', ['previous_stage' => 'new'])] as $item) {
        ReferralEvent::create(['referral_id' => $legacyRecord->id, 'kind' => $item['kind'], 'audience' => 'intake', 'payload' => $item['payload'], 'occurred_at' => $item['at']]);
    }
    $r = $reports->build($actor->fresh(), $filters, $at('18'));
    $new = array_column($r['history']['stages'], null, 'key')['new'];
    $check($new['elapsed']['count'] === 2 && $new['working']['count'] === 1 && $new['unknown_calendar'] === 1, 'Missing original calendar has a separate denominator and is never a zero-hour observation');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    DB::rollBack();
}
