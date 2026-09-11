<?php

use App\Domain\It\Services\ItAutomationFreshnessService;
use App\Domain\It\Services\ItSlaClockService;
use App\Models\ItAutomationRun;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Support\It\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $this->previousFacadeApp = Facade::getFacadeApplication();
    $container = new Container;
    $container->instance('config', new Repository(['app' => ['timezone' => 'UTC', 'worker_timezone' => 'Pacific/Auckland']]));
    Container::setInstance($container);
    Facade::setFacadeApplication($container);
});

afterEach(function () {
    Container::setInstance($this->previousContainer);
    Facade::setFacadeApplication($this->previousFacadeApp);
});

function slaClockInstant(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Pacific/Auckland');
}

function slaClockFixture(array $changes = []): ItTicket
{
    $at = slaClockInstant('2026-09-07 08:00');
    $policy = (new ItSlaPolicy)->forceFill([
        'priority' => 'normal', 'first_response_minutes' => 120, 'resolution_minutes' => 480,
        ...BusinessHours::nzDefault(),
    ]);

    return (new ItTicket)->setDateFormat('Y-m-d H:i:s')->forceFill([
        'status' => 'open', 'priority' => 'normal', 'created_at' => $at->utc(),
        'first_response_due_at' => $at->addHours(2)->utc(),
        'resolution_due_at' => $at->addHours(8)->utc(),
        'sla_policy_snapshot' => (new ItSlaClockService)->policySnapshot('normal', $policy, $at),
        ...$changes,
    ]);
}

test('missing clocks are unmeasured regardless of a stored healthy label', function () {
    $ticket = slaClockFixture(['first_response_due_at' => null, 'resolution_due_at' => null, 'sla_state' => 'ok']);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-07 09:00'));
    expect($verdict['state'])->toBe('unmeasured')->and($verdict['coverage'])->toBe('none');
});

test('a response timestamp before the ticket existed cannot prove compliant measurement', function () {
    $ticket = slaClockFixture(['first_responded_at' => slaClockInstant('2026-09-07 07:59')->utc()]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-07 09:00'));
    expect($verdict['state'])->toBe('unmeasured')
        ->and($verdict['clocks']['first_response']['reason'])->toBe('invalid_clock_order');
});

test('a late first response remains breached while the independent resolution clock is running', function () {
    $ticket = slaClockFixture(['first_responded_at' => slaClockInstant('2026-09-07 10:01')->utc()]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-07 11:00'));
    expect($verdict['state'])->toBe('breached')
        ->and($verdict['clocks']['first_response']['state'])->toBe('breached')
        ->and($verdict['clocks']['resolution']['state'])->toBe('ok')
        ->and($verdict['clocks']['first_response']['breached_at'])->toBe($ticket->first_response_due_at->toIso8601String());
});

test('resolution can breach after an on-time first response and settlement never erases it', function () {
    $ticket = slaClockFixture([
        'status' => 'resolved', 'first_responded_at' => slaClockInstant('2026-09-07 09:00')->utc(),
        'resolved_at' => slaClockInstant('2026-09-07 16:01')->utc(), 'sla_state' => 'met',
    ]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-08 12:00'));
    expect($verdict['state'])->toBe('breached')->and($verdict['coverage'])->toBe('full')
        ->and($verdict['clocks']['first_response']['state'])->toBe('met')
        ->and($verdict['clocks']['resolution']['state'])->toBe('breached');
});

test('retained breach evidence survives a later target extension', function () {
    $ticket = slaClockFixture([
        'first_response_breached_at' => slaClockInstant('2026-09-07 10:00')->utc(),
        'first_response_due_at' => slaClockInstant('2026-09-07 15:00')->utc(),
        'first_responded_at' => slaClockInstant('2026-09-07 14:00')->utc(),
    ]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-07 14:00'));
    expect($verdict['state'])->toBe('breached')
        ->and($verdict['clocks']['first_response']['breached_at'])->toBe(slaClockInstant('2026-09-07 10:00')->utc()->toIso8601String());
});

test('business pause skips a holiday weekend and NZ daylight saving instead of extending by wall time', function () {
    $start = slaClockInstant('2026-09-25 15:00');
    $policy = (new ItSlaPolicy)->forceFill([
        'priority' => 'normal', 'first_response_minutes' => 60, 'resolution_minutes' => 120,
        ...BusinessHours::nzDefault(), 'holiday_dates' => ['2026-09-28'],
    ]);
    $ticket = slaClockFixture([
        'status' => 'waiting', 'created_at' => $start->utc(),
        'first_response_due_at' => $start->addHour()->utc(),
        'first_responded_at' => $start->addMinutes(30)->utc(),
        'resolution_due_at' => $start->addHours(2)->utc(),
        'waiting_since' => slaClockInstant('2026-09-25 16:00')->utc(),
        'sla_policy_snapshot' => (new ItSlaClockService)->policySnapshot('normal', $policy, $start),
    ]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-29 09:00'));
    expect($verdict['state'])->toBe('paused')
        ->and($verdict['clocks']['resolution']['paused_minutes'])->toBe(120)
        ->and($verdict['clocks']['resolution']['due_at'])->toBe(slaClockInstant('2026-09-29 10:00')->utc()->toIso8601String());
});

test('known deadlines with an unrecorded pause calendar cannot invent compliance', function () {
    $ticket = slaClockFixture(['sla_policy_snapshot' => null, 'sla_paused_minutes' => 120]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-07 09:00'));
    expect($verdict['state'])->toBe('unmeasured')
        ->and($verdict['clocks']['resolution']['reason'])->toBe('pause_calendar_not_recorded');
});

test('a known settled deadline can prove met without backfilling an old policy', function () {
    $ticket = slaClockFixture([
        'status' => 'resolved', 'sla_policy_snapshot' => null,
        'first_responded_at' => slaClockInstant('2026-09-07 09:00')->utc(),
        'resolved_at' => slaClockInstant('2026-09-07 16:00')->utc(),
    ]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-08 12:00'));
    expect($verdict['state'])->toBe('met')->and($verdict['coverage'])->toBe('full')
        ->and($verdict['clocks']['resolution']['policy_recorded'])->toBeFalse();
});

test('reopened resolution stays explicitly unmeasured until its new episode policy is defined', function () {
    $ticket = slaClockFixture(['reopened_count' => 1, 'first_responded_at' => slaClockInstant('2026-09-07 09:00')->utc()]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-08 12:00'));
    expect($verdict['state'])->toBe('unmeasured')
        ->and($verdict['clocks']['resolution']['reason'])->toBe('reopen_clock_policy_required');
});

test('resolving a reopened episode cannot invent a target for that episode', function () {
    $ticket = slaClockFixture([
        'reopened_count' => 1, 'status' => 'resolved',
        'first_responded_at' => slaClockInstant('2026-09-07 09:00')->utc(),
        'resolved_at' => slaClockInstant('2026-09-08 11:00')->utc(),
    ]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-09-08 12:00'));
    expect($verdict['state'])->toBe('unmeasured')
        ->and($verdict['clocks']['resolution']['reason'])->toBe('reopen_clock_policy_required');
});

test('missing settlement timestamps cannot be replaced with the current time', function () {
    $ticket = slaClockFixture(['status' => 'closed', 'resolved_at' => null, 'first_responded_at' => null]);
    $verdict = (new ItSlaClockService)->verdict($ticket, slaClockInstant('2026-10-01 12:00'));
    expect($verdict['state'])->toBe('unmeasured')->and($verdict['coverage'])->toBe('none');
});

test('snapshots preserve configured target values and distinguish defaults without a policy lookup', function () {
    $service = new ItSlaClockService;
    $at = slaClockInstant('2026-09-07 08:00');
    $policy = (new ItSlaPolicy)->forceFill(['id' => 7, 'first_response_minutes' => 35, 'resolution_minutes' => 95]);
    $snapshot = $service->policySnapshot('high', $policy, $at);
    $policy->first_response_minutes = 5;
    expect($snapshot['source'])->toBe('configured')->and($snapshot['first_response_minutes'])->toBe(35)
        ->and($snapshot['resolution_minutes'])->toBe(95)
        ->and($service->policySnapshot('urgent', null, $at)['first_response_minutes'])->toBe(60);
});

test('calendar snapshots retain their timezone if the application display timezone changes', function () {
    $calendar = [...BusinessHours::nzDefault(), 'timezone' => 'Pacific/Auckland'];
    config(['app.worker_timezone' => 'UTC']);
    $start = slaClockInstant('2026-09-07 08:00');
    expect(BusinessHours::addWorkingMinutes($start, 60, $calendar)->equalTo($start->addHour()))->toBeTrue();
});

test('overlapping and unordered windows count working time exactly once', function () {
    $calendar = ['business_hours' => ['mon' => [['10:00', '12:00'], ['08:00', '11:00']]]];
    $start = slaClockInstant('2026-09-07 08:00');
    expect(BusinessHours::workingMinutesBetween($start, $start->addHours(4), $calendar))->toBe(240)
        ->and(BusinessHours::addWorkingMinutes($start, 240, $calendar)->equalTo($start->addHours(4)))->toBeTrue();
});

test('NZ daylight saving counts actual working minutes inside a Sunday window', function () {
    $calendar = ['business_hours' => ['sun' => [['00:00', '05:00']]], 'timezone' => 'Pacific/Auckland'];
    $spring = slaClockInstant('2026-09-27 00:00');
    $autumn = slaClockInstant('2026-04-05 00:00');
    expect(BusinessHours::workingMinutesBetween($spring, $spring->setTime(5, 0), $calendar))->toBe(240)
        ->and(BusinessHours::addWorkingMinutes($spring, 180, $calendar)->format('H:i'))->toBe('04:00')
        ->and(BusinessHours::workingMinutesBetween($autumn, $autumn->setTime(5, 0), $calendar))->toBe(360)
        ->and(BusinessHours::addWorkingMinutes($autumn, 240, $calendar)->format('H:i'))->toBe('03:00');
});

test('invalid or exhausted calendars never fabricate a wall-clock deadline', function () {
    $start = slaClockInstant('2026-09-07 08:00');
    expect(fn () => BusinessHours::addWorkingMinutes($start, 60, ['business_hours' => ['mon' => [['17:00', '08:00']]]]))
        ->toThrow(DomainException::class)
        ->and(fn () => BusinessHours::addWorkingMinutes($start, 5000, ['business_hours' => ['mon' => [['08:00', '08:01']]]]))
        ->toThrow(DomainException::class);
});

test('watchdog freshness distinguishes absent stopped failed and recovered execution', function () {
    $service = new ItAutomationFreshnessService;
    $at = slaClockInstant('2026-09-07 10:06');
    $run = fn (string $status, string $start, ?string $finish = null) => (new ItAutomationRun)->setDateFormat('Y-m-d H:i:s')->forceFill([
        'status' => $status, 'started_at' => slaClockInstant($start)->utc(),
        'finished_at' => $finish ? slaClockInstant($finish)->utc() : null,
    ]);
    $old = $run('succeeded', '2026-09-07 09:00', '2026-09-07 09:01');
    $failed = $run('failed', '2026-09-07 10:00', '2026-09-07 10:01');
    $good = $run('succeeded', '2026-09-07 10:03', '2026-09-07 10:04');
    $stuck = $run('running', '2026-09-07 08:00');
    expect($service->verdict('0 * * * *', 'Pacific/Auckland', null, null, $at)['state'])->toBe('unmeasured')
        ->and($service->verdict('0 * * * *', 'Pacific/Auckland', $old, $old, $at)['state'])->toBe('stale')
        ->and($service->verdict('0 * * * *', 'Pacific/Auckland', $failed, $old, $at)['state'])->toBe('failed')
        ->and($service->verdict('0 * * * *', 'Pacific/Auckland', $good, $good, $at)['state'])->toBe('fresh')
        ->and($service->verdict('0 * * * *', 'Pacific/Auckland', $stuck, null, $at)['state'])->toBe('stale')
        ->and($service->verdict('0 * * * *', 'Pacific/Auckland', $old, $old, $at->setTime(10, 3))['state'])->toBe('fresh');
});

test('summary consumes a lazy authorized sequence once and preserves honest coverage denominators', function () {
    $at = slaClockInstant('2026-09-07 09:00');
    $visited = 0;
    $tickets = (function () use (&$visited, $at) {
        $visited++;
        yield slaClockFixture();
        $visited++;
        yield slaClockFixture(['first_response_due_at' => null, 'resolution_due_at' => null]);
        $visited++;
        yield slaClockFixture(['first_response_due_at' => null]);
        $visited++;
        yield slaClockFixture(['first_responded_at' => $at->utc(), 'status' => 'waiting', 'waiting_since' => $at->utc()]);
        $visited++;
        yield slaClockFixture(['first_response_breached_at' => $at->subMinute()->utc()]);
    })();
    $summary = (new ItSlaClockService)->summarize($tickets, $at);
    expect($visited)->toBe(5)->and($summary['total'])->toBe(5)
        ->and($summary['by_state'])->toBe(['ok' => 1, 'at_risk' => 0, 'breached' => 1, 'met' => 0, 'paused' => 1, 'unmeasured' => 2])
        ->and($summary['by_coverage'])->toBe(['none' => 1, 'partial' => 1, 'full' => 3])
        ->and($summary['ever_breached'])->toBe(1)
        ->and(array_sum($summary['clocks']['first_response']['by_state']))->toBe(5)
        ->and($summary['clocks']['first_response']['by_state']['unmeasured'])->toBe(2)
        ->and($summary['evaluated_at'])->toBe($at->utc()->toIso8601String());
});

test('an empty authorized summary reports zero observed records and all state buckets', function () {
    $summary = (new ItSlaClockService)->summarize([], slaClockInstant('2026-09-07 09:00'));
    expect($summary['total'])->toBe(0)->and($summary['ever_breached'])->toBe(0)
        ->and(array_sum($summary['by_state']))->toBe(0)
        ->and($summary['by_coverage'])->toBe(['none' => 0, 'partial' => 0, 'full' => 0]);
});
