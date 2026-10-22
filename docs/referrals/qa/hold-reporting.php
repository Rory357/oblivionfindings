<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$at = fn ($time) => CarbonImmutable::parse('2026-10-16T'.$time.':00+13:00')->utc();
$clock = function ($time) use ($at) {
    Carbon::setTestNow($at($time));
    CarbonImmutable::setTestNow($at($time));
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.restricted')->sole()->id, ['allowed' => false]);
    $config = ReferralSettingsVersion::create(['version' => 50020, 'state' => 'published', 'configuration' => ReferralSettings::defaults(), 'published_at' => $at('09:00')->subDay(), 'effective_at' => $at('09:00')->subDay()]);
    $record = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole()->replicate(['reference']);
    $record->forceFill(['source' => 'qa_hold_report', 'settings_version_id' => $config->id, 'owner_id' => $actor->id, 'stage' => 'assessment', 'hold_reason' => null, 'created_at' => $at('09:00')->subDay(), 'stage_changed_at' => $at('09:00')->subDay(), 'primary_work_item_id' => null, 'next_due_at' => null]);
    $record->save();
    ReferralEvent::create(['referral_id' => $record->id, 'kind' => 'referral.received', 'audience' => 'intake', 'payload' => ['settings_version_id' => $config->id], 'occurred_at' => $record->created_at]);
    $lifecycle = app(ReferralLifecycle::class);
    $reports = app(ReferralReports::class);
    $clock('09:00');
    $filters = $reports->filters(['from' => '2026-10-15', 'to' => '2026-10-16', 'source' => 'qa_hold_report']);
    $report = fn ($time) => $reports->build($actor->fresh(), $filters, $at($time));
    $hold = function ($reason) use ($record, $actor, $lifecycle, $at) {
        $lifecycle->hold($actor->fresh(), $record->id, $record->fresh()->lock_version, ['hold_reason' => $reason, 'reason' => 'Fictional hold reporting verification', 'next_action' => 'Review the fictional waiting reason', 'next_due_at' => $at('17:00')->addDays(4)->toISOString()]);
    };
    $hold('capacity_waitlist');
    $r = $report('12:00');
    $times = array_column($r['working_time'], null, 'key');
    $check($record->fresh()->stage === 'assessment' && $record->fresh()->stage_changed_at->equalTo($at('09:00')->subDay()), 'Real hold command preserves progress stage and stage clock');
    $check(array_column($r['metrics'], 'value', 'key')['capacity_waitlist'] === 1, 'Capacity waitlist counts an assessment-stage hold');
    $check($times['hold']['elapsed']['mean_hours'] === 3.0 && $times['hold']['working']['mean_hours'] === 3.0, 'Current hold starts at the actual hold event, not the stage change');
    $check($times['stage']['elapsed']['mean_hours'] === 27.0, 'Stage duration remains independent from hold duration');
    $check($reports->metric($reports->query($actor->fresh(), $filters, $at('12:00')), $actor->fresh(), 'capacity_waitlist', $at('12:00'))->count() === 1, 'Waitlist metric drill-through agrees with its count');
    $clock('10:00');
    $hold('awaiting_evidence');
    $r = $report('12:00');
    $times = array_column($r['working_time'], null, 'key');
    $check($times['hold']['elapsed']['mean_hours'] === 3.0, 'Changing hold reason retains the uninterrupted waiting clock');
    $check(array_column($r['groups']['hold_reason'], 'count', 'key') === ['awaiting_evidence' => 1] && array_column($r['metrics'], 'value', 'key')['capacity_waitlist'] === 0, 'Reason breakdown follows the current hold independently of stage');
    $clock('11:00');
    $hold(null);
    $r = $report('12:00');
    $check(array_column($r['working_time'], null, 'key')['hold']['elapsed']['count'] === 0 && $r['groups']['hold_reason'] === [], 'Released hold leaves current waiting measures without altering stage');
    $clock('12:00');
    $hold('capacity_waitlist');
    $r = $report('13:00');
    $check(array_column($r['working_time'], null, 'key')['hold']['elapsed']['mean_hours'] === 1.0, 'A new hold after release starts a new interval');
    $clock('14:00');
    $lifecycle->close($actor->fresh(), $record->id, $record->fresh()->lock_version, ['closure_reason' => 'other', 'reason' => 'Fictional closure for reporting verification', 'person_informed' => false, 'communication_note' => 'Fictional test; no real person contacted', 'cancel_open_work' => true]);
    $r = $report('14:30');
    $check(array_column($r['working_time'], null, 'key')['hold']['elapsed']['count'] === 0 && array_column($r['metrics'], 'value', 'key')['capacity_waitlist'] === 0, 'Closure removes current hold counts and retains history');
    $clock('15:00');
    $lifecycle->reopen($actor->fresh(), $record->id, $record->fresh()->lock_version, ['reason' => 'Fictional renewed enquiry', 'next_action' => 'Review fictional reopened enquiry', 'next_due_at' => $at('17:00')->addDay()->toISOString()]);
    $clock('16:00');
    $hold('capacity_waitlist');
    $r = $report('16:30');
    $check(array_column($r['working_time'], null, 'key')['hold']['elapsed']['mean_hours'] === 0.5, 'Reopened referral starts a new hold without reusing closed-episode time');
    $missing = $record->fresh()->replicate(['reference']);
    $missing->save();
    $hidden = $record->fresh()->replicate(['reference']);
    $hidden->restricted = true;
    $hidden->save();
    $r = $report('16:30');
    $times = array_column($r['working_time'], null, 'key');
    $check($times['hold']['unknown_dates'] === 1 && $times['hold']['elapsed']['count'] === 1, 'Modern hold without provenance remains unavailable; restricted hold is excluded');
    $check(array_column($r['metrics'], 'value', 'key')['capacity_waitlist'] === 2, 'Current hold existence is counted even when historical start time is unknown');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    DB::rollBack();
}
