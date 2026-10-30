<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralHistoryReport;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralWorkingTime;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $config = ReferralSettings::defaults();
    $version = ReferralSettingsVersion::create(['version' => 50010, 'state' => 'published', 'configuration' => $config, 'published_at' => now(), 'effective_at' => now()]);
    $template = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole();
    $cutoff = CarbonImmutable::now('UTC')->startOfSecond();
    $rows = [];
    for ($i = 0; $i < 10000; $i++) {
        $record = $template->replicate(['reference']);
        $from = $cutoff->subDays(730)->addMinutes($i % 1440);
        $record->forceFill(['reference' => (string) Str::ulid(), 'source' => 'qa_working_volume', 'settings_version_id' => $version->id, 'created_at' => $from, 'updated_at' => $cutoff, 'stage_changed_at' => $from, 'stage' => 'assessment', 'owner_id' => null, 'next_due_at' => $from, 'first_contact_attempt_at' => null, 'first_human_contact_at' => null, 'restricted' => false]);
        $rows[] = $record->getAttributes();
        if (count($rows) === 500) {
            DB::table('care_referrals')->insert($rows);
            $rows = [];
        }
    }
    $ids = CareReferral::where('source', 'qa_working_volume')->pluck('id');
    foreach ($ids->chunk(500) as $chunk) {
        $rows = [];
        foreach ($chunk as $id) {
            $event = new ReferralEvent(['reference' => (string) Str::ulid(), 'referral_id' => $id, 'kind' => 'referral.received', 'audience' => 'intake', 'payload' => ['settings_version_id' => $version->id], 'occurred_at' => $cutoff->subDays(730)]);
            $rows[] = $event->getAttributes();
        }
        DB::table('care_referral_events')->insert($rows);
    }
    $service = app(ReferralReports::class);
    $filters = $service->filters(['scope' => 'current', 'source' => 'qa_working_volume']);
    $started = hrtime(true);
    $report = $service->build($actor, $filters, $cutoff);
    $elapsed = (hrtime(true) - $started) / 1e6;
    $working = array_column($report['working_time'], null, 'key');
    if ($report['metrics'][0]['value'] !== 10000 || $working['stage']['working']['count'] !== 10000 || $working['overdue']['working']['count'] !== 10000 || $working['unassigned']['working']['count'] !== 10000) {
        throw new RuntimeException('Volume report denominator mismatch');
    }
    // These old synthetic fixtures deliberately have no event supporting their Assessment stage.
    if ($report['history']['referrals'] !== 10000 || $report['history']['incomplete_referrals'] !== 10000 || $report['history']['stages'] !== []) {
        throw new RuntimeException('Volume report invented missing stage history');
    }
    echo json_encode(['passed' => true, 'referrals' => 10000, 'report_ms' => round($elapsed, 2), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'stage' => $working['stage'], 'overdue' => $working['overdue']]).PHP_EOL;
    if (($argv[2] ?? '') === 'profile') {
        $query = $service->query($actor, $filters, $cutoff);
        $started = hrtime(true);
        $calendars = app(ReferralWorkingTime::class)->calendars(clone $query);
        $calendarMs = (hrtime(true) - $started) / 1e6;
        $started = hrtime(true);
        $profileWorking = app(ReferralWorkingTime::class)->report($actor, clone $query, $cutoff, $calendars);
        $workingMs = (hrtime(true) - $started) / 1e6;
        $started = hrtime(true);
        $profileHistory = app(ReferralHistoryReport::class)->build(clone $query, $cutoff, $calendars);
        $historyMs = (hrtime(true) - $started) / 1e6;
        if ($profileWorking !== $report['working_time'] || $profileHistory !== $report['history']) {
            throw new RuntimeException('Profiled services changed report output');
        }
        echo json_encode(['profile_ms' => ['calendar' => round($calendarMs, 2), 'working' => round($workingMs, 2), 'history' => round($historyMs, 2)]]).PHP_EOL;
    }
} finally {
    DB::rollBack();
}
