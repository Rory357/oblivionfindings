<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Http\Controllers\ReferralReportController;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralWorkingTime;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$calendar = ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2, 3, 4, 5], 'opening_time' => '09:00', 'closing_time' => '17:00', 'holidays' => []];
$hours = fn ($from, $to, $config = null) => ($seconds = ReferralWorkingTime::seconds(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $config ?? $calendar)) === null ? null : $seconds / 3600;
$check($hours('2026-10-16T08:00:00+13:00', '2026-10-16T18:00:00+13:00') === 8.0, 'Clamp to opening and closing boundaries');
$check($hours('2026-10-16T16:00:00+13:00', '2026-10-19T10:00:00+13:00') === 2.0, 'Weekend does not count as working time');
$check($hours('2026-10-16T16:00:00+13:00', '2026-10-20T10:00:00+13:00', [...$calendar, 'holidays' => ['2026-10-19']]) === 2.0, 'Published holiday is excluded');
$check($hours('2026-10-17T10:00:00+13:00', '2026-10-18T10:00:00+13:00') === 0.0, 'Known weekend interval is a measured zero');
$check($hours('2026-10-16T08:00:00+13:00', '2026-10-16T08:00:00+13:00') === 0.0, 'Same-time contact remains a valid zero');
$check($hours('2026-10-16T10:00:00+13:00', '2026-10-16T09:00:00+13:00') === null, 'Reversed timestamps are unavailable');
$legacy = $calendar;
unset($legacy['opening_time']);
$check($hours('2026-10-16T10:00:00+13:00', '2026-10-16T11:00:00+13:00', $legacy) === null, 'Legacy opening time is not invented');
$sunday = [...$calendar, 'weekdays' => [7], 'opening_time' => '01:00', 'closing_time' => '04:00'];
$check($hours('2026-09-27T00:00:00+12:00', '2026-09-27T06:00:00+13:00', $sunday) === 2.0, 'NZ spring DST counts real office-window seconds');
$check($hours('2026-04-05T00:00:00+13:00', '2026-04-05T06:00:00+12:00', $sunday) === 4.0, 'NZ autumn DST counts the repeated elapsed hour');
$check($hours('2026-04-05T00:00:00+13:00', '2026-04-05T06:00:00+12:00', [...$sunday, 'opening_time' => '02:30']) === null, 'Ambiguous repeated-hour opening is not guessed');
$check($hours('2026-09-27T00:00:00+12:00', '2026-09-27T06:00:00+13:00', [...$sunday, 'opening_time' => '02:30']) === null, 'Nonexistent spring opening is not normalized silently');
$check($hours('2026-10-16T08:00:00+13:45', '2026-10-16T18:00:00+13:45', [...$calendar, 'timezone' => 'Pacific/Chatham']) === 8.0, 'Chatham quarter-hour offset uses the local office window');
$config = ReferralSettings::defaults();
$config['business_calendar']['opening_time'] = '18:00';
try {
    app(ReferralSettings::class)->validate($config);
    throw new RuntimeException('Reversed opening accepted');
} catch (ValidationException $e) {
    $check(isset($e->errors()['configuration.business_calendar.closing_time']), 'Settings reject an overnight or reversed window');
}
$config['business_calendar'] = $legacy;
$check(! array_key_exists('opening_time', app(ReferralSettings::class)->validate($config)['business_calendar']), 'Legacy settings retain unknown opening when edited');
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16T18:00:00+13:00'));
try {
    $deadline = new ReflectionMethod(PublicReferralIntake::class, 'dueAt');
    $target = ReferralSettings::defaults();
    $target['business_calendar']['holidays'] = ['2026-10-19'];
    $target['business_calendar']['weekdays'] = ['1', '2', '3', '4', '5'];
    $due = $deadline->invoke(app(PublicReferralIntake::class), $target);
    $check($due->timezone('Pacific/Auckland')->format('Y-m-d H:i') === '2026-10-21 17:00', 'Website first-contact target remains closing time of the second future working day, skipping weekend and holiday');
    $check(app(ReferralSettings::class)->validate($target)['business_calendar']['weekdays'] === [1, 2, 3, 4, 5], 'Accepted numeric weekday strings normalize before publication');
} finally {
    CarbonImmutable::setTestNow();
}

DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.restricted')->sole()->id, ['allowed' => false]);
    $config['business_calendar'] = $calendar;
    $original = ReferralSettingsVersion::create(['version' => 50001, 'state' => 'published', 'configuration' => $config, 'published_at' => now(), 'effective_at' => now()]);
    $changed = $config;
    $changed['business_calendar']['opening_time'] = '12:00';
    $later = ReferralSettingsVersion::create(['version' => 50002, 'state' => 'published', 'configuration' => $changed, 'published_at' => now(), 'effective_at' => now()]);
    $template = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole();
    $from = CarbonImmutable::parse('2026-10-16T16:00:00+13:00')->utc();
    $cutoff = CarbonImmutable::parse('2026-10-19T10:00:00+13:00')->utc();
    $make = function ($event, $restricted = false) use ($template, $original, $later, $from, $cutoff) {
        $record = $template->replicate(['reference']);
        $record->forceFill(['source' => 'qa_working_time', 'settings_version_id' => $later->id, 'created_at' => $from, 'stage_changed_at' => $from, 'stage' => 'on_hold', 'hold_reason' => 'capacity_waitlist', 'owner_id' => null, 'restricted' => $restricted, 'first_contact_attempt_at' => $from, 'first_human_contact_at' => $cutoff, 'next_due_at' => $from]);
        $record->save();
        if ($event) {
            ReferralEvent::create(['referral_id' => $record->id, 'kind' => 'referral.received', 'audience' => 'intake', 'payload' => ['settings_version_id' => $original->id], 'occurred_at' => $from]);
        }

        return $record;
    };
    $record = $make(true);
    $make(false);
    $make(true, true);
    ReferralWorkItem::create(['referral_id' => $record->id, 'owner_id' => $actor->id, 'kind' => 'follow_up', 'status' => 'open', 'audience' => 'intake', 'details' => ['title' => 'Fictional working-time assertion'], 'due_at' => $from->addHour()]);
    $service = app(ReferralReports::class);
    $filters = $service->filters(['scope' => 'current', 'source' => 'qa_working_time']);
    $result = $service->build($actor->fresh(), $filters, $cutoff);
    $rows = array_column($result['working_time'], null, 'key');
    $check($rows['contact']['working'] === ['count' => 1, 'mean_hours' => 2.0], 'Working contact uses original intake calendar, not migrated or later settings');
    $check($rows['contact']['elapsed'] === ['count' => 2, 'mean_hours' => 66.0], 'Elapsed contact retains all valid timestamps with its own denominator');
    $check($rows['contact']['unknown_calendar'] === 1, 'Missing intake provenance is reported separately');
    $check($rows['attempt']['working']['mean_hours'] === 0.0, 'Immediate recorded human attempt is measured zero');
    foreach (['stage', 'hold', 'unassigned', 'overdue'] as $key) {
        $check($rows[$key]['elapsed']['count'] === 2 && $rows[$key]['working']['mean_hours'] === 2.0 && $rows[$key]['working']['count'] === 1, 'Scoped '.$key.' elapsed and working means use correct denominators');
    }
    $check($rows['overdue']['elapsed']['mean_hours'] === 66.0, 'Multiple overdue actions use the oldest deadline and count the referral once');
    CarbonImmutable::setTestNow($cutoff);
    $request = Request::create('/operations/referrals/reports/export', 'POST', [...$filters, 'format' => 'summary']);
    $request->setUserResolver(fn () => $actor->fresh());
    $csv = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class))->getContent();
    $check(str_contains($csv, '"Successful human contact",66,2,2,1,1,0'), 'Summary CSV preserves both mean-hour values, denominators and unavailable count');
    $otherCalendar = $make(false);
    ReferralEvent::create(['referral_id' => $otherCalendar->id, 'kind' => 'referral.received', 'audience' => 'intake', 'payload' => ['settings_version_id' => $later->id], 'occurred_at' => $from]);
    $mixed = array_column($service->build($actor->fresh(), $filters, $cutoff)['working_time'], null, 'key');
    $check($mixed['contact']['working'] === ['count' => 2, 'mean_hours' => 1.5], 'Identical timestamps across referrals retain different original calendars');
    $check($mixed['contact']['unknown_calendar'] === 1, 'A known interval never fills a missing calendar on another referral');
    $check($mixed['stage']['working'] === $mixed['unassigned']['working'] && $mixed['stage']['working'] === $mixed['overdue']['working'], 'Reused same-referral intervals retain separate metric denominators');
    $laterReport = array_column($service->build($actor->fresh(), $filters, $cutoff->addHours(4))['working_time'], null, 'key');
    $check($laterReport['stage']['working'] === ['count' => 2, 'mean_hours' => 4.5] && $laterReport['contact']['working'] === $mixed['contact']['working'], 'A later report recomputes ongoing time without changing recorded contact duration');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    CarbonImmutable::setTestNow();
    DB::rollBack();
}
