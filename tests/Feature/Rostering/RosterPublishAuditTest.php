<?php

use App\Events\RosterPeriodPublished;
use App\Models\AuditLog;
use App\Models\RosterPeriod;
use App\Models\User;

/*
 * Publishing a roster period is a manager decision about paid work, so it
 * gets an audit-log row (action `rostering.period.published`) via the
 * RecordRosterPeriodPublishedAudit listener. Deliberately NOT a client
 * TimelineEvent — planning events would pollute care timelines.
 */

it('writes an audit row when a roster period is published', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $period = RosterPeriod::factory()->create([
        'organization_id' => 1,
        'shift_count' => 7,
        'published_by' => $actor->id,
        'published_at' => now(),
    ]);

    event(new RosterPeriodPublished($period, $actor, false));

    $log = AuditLog::query()->where('action', 'rostering.period.published')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->auditable_type)->toBe($period->getMorphClass())
        ->and((int) $log->auditable_id)->toBe($period->id)
        ->and($log->meta['actor_id'])->toBe($actor->id)
        ->and($log->meta['republished'])->toBeFalse()
        ->and($log->meta['shift_count'])->toBe(7)
        ->and($log->meta['week_start'])->toBe(optional($period->week_start)->toDateString());
});

it('flags republish in the audit meta', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $period = RosterPeriod::factory()->create([
        'organization_id' => 1,
        'version' => 2,
    ]);

    event(new RosterPeriodPublished($period, $actor, true));

    $log = AuditLog::query()->where('action', 'rostering.period.published')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->meta['republished'])->toBeTrue()
        ->and($log->meta['version'])->toBe(2);
});
