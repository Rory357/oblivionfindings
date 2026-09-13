<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItSlaClockService;
use App\Models\ItAutomationRun;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-09T02:10:00Z'));
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    foreach (['agent' => 'hr', 'worker' => 'support_worker'] as $key => $role) {
        $this->{$key} = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $this->{$key}->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->{$key}->id, 'primary_site_id' => $this->site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);
    }
    $policy = (new ItSlaPolicy)->forceFill(['first_response_minutes' => 60, 'resolution_minutes' => 240]);
    $snapshot = app(ItSlaClockService::class)->policySnapshot('normal', $policy, now()->subHours(2));
    $this->fixtures = collect();
    foreach (['ok', 'at_risk', 'breached', 'met', 'paused', 'unmeasured', 'partial', 'resolved_unknown'] as $case) {
        $created = $case === 'at_risk' ? now()->subMinutes(50) : now()->subHours(2);
        $unknown = in_array($case, ['unmeasured', 'resolved_unknown'], true);
        $settled = in_array($case, ['met', 'resolved_unknown'], true);
        $responded = in_array($case, ['breached', 'met', 'paused'], true);
        $this->fixtures[$case] = ItTicket::factory()->create([
            'site_id' => $this->site->id, 'requester_user_id' => $this->worker->id,
            'created_at' => $case === 'ok' || $case === 'partial' ? now() : $created,
            'status' => $settled ? 'resolved' : ($case === 'paused' ? 'waiting' : 'open'),
            // Deliberately wrong cache: read surfaces must derive the evidence.
            'sla_state' => 'ok', 'sla_policy_snapshot' => $unknown ? null : $snapshot,
            'first_response_due_at' => $unknown || $case === 'partial' ? null : ($case === 'ok' ? now()->addHour() : $created->copy()->addHour()),
            'resolution_due_at' => $unknown ? null : $created->copy()->addHours(4),
            'first_responded_at' => $responded ? $created->copy()->addMinutes($case === 'breached' ? 70 : 30) : null,
            'resolved_at' => $settled ? $created->copy()->addHour() : null,
            'waiting_since' => $case === 'paused' ? now()->subHour() : null,
            'sla_paused_minutes' => 0, 'reopened_count' => 0,
        ]);
    }
    ItTicket::factory()->create([
        'site_id' => Site::factory()->create()->id, 'requester_user_id' => User::factory()->create()->id,
        'created_at' => now()->subHours(2), 'first_response_due_at' => now()->subHour(),
        'sla_state' => 'breached', 'sla_policy_snapshot' => $snapshot,
    ]);
});

test('live header queue detail reports and CSV reconcile without trusting stale SLA state', function () {
    $this->actingAs($this->agent)->get('/it')->assertInertia(fn (Assert $page) => $page
        ->where('summary.tickets.open', 6)->where('summary.tickets.at_risk', 1)->where('summary.tickets.breached', 1)
        ->where('summary.tickets.sla_open.by_coverage', ['none' => 1, 'partial' => 1, 'full' => 4])
        ->where('summary.tickets.sla_open.by_state.paused', 1)->where('summary.tickets.sla_open.by_state.unmeasured', 2)
        ->where('summary.tickets.resolved_30d', 2)->where('summary.tickets.met_30d', 1)->where('summary.tickets.measured_30d', 1)
        ->where('summary.tickets.sla_watchdog.state', 'unmeasured')->has('overview.sla_lane', 2));
    foreach ($this->fixtures as $case => $ticket) {
        $expected = in_array($case, ['partial', 'resolved_unknown'], true) ? 'unmeasured' : $case;
        $this->getJson('/it/tickets/'.$ticket->id)->assertOk()->assertJsonPath('ticket.sla.state', $expected);
    }
    $this->getJson('/it/reports/data')->assertOk()
        ->assertJsonPath('kpis.breaching', 1)->assertJsonPath('kpis.breached', 1)
        ->assertJsonPath('kpis.sla_met', 1)->assertJsonPath('kpis.sla_measured', 1)
        ->assertJsonPath('kpis.sla_compliance', 100)->assertJsonPath('kpis.sla_resolved.by_coverage.none', 1);
    $csv = $this->get('/it/reports/export?card=summary')->assertOk()->streamedContent();
    $rows = collect(explode("\n", trim($csv)))->map(fn ($line) => str_getcsv($line, ',', '"', ''))
        ->filter(fn ($row) => count($row) === 2)->mapWithKeys(fn ($row) => [$row[0] => trim($row[1])]);
    expect($rows['SLA at risk'])->toBe('1')->and($rows['SLA breached'])->toBe('1')
        ->and($rows['SLA fully measured in range'])->toBe('1')->and($rows['SLA no measurement in range'])->toBe('1')
        ->and($rows['SLA unmeasured open tickets'])->toBe('2')->and($rows['SLA watchdog'])->toBe('unmeasured');
    expect($this->fixtures['breached']->fresh()->sla_state)->toBe('ok'); // GET never rewrites history.
});

test('every SLA filter uses live evidence before pagination and excludes an unapproved Site', function (string $state, array $cases) {
    $this->actingAs($this->agent)->get('/it?tab=tickets&sla='.$state)->assertInertia(fn (Assert $page) => $page
        ->where('tickets.total', count($cases))->has('tickets.data', count($cases))
        ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
            === collect($cases)->map(fn ($case) => $this->fixtures[$case]->id)->sort()->values()->all()));
})->with([
    ['ok', ['ok']], ['at_risk', ['at_risk']], ['breached', ['breached']],
    ['met', ['met']], ['paused', ['paused']], ['unmeasured', ['unmeasured', 'partial', 'resolved_unknown']],
]);

test('the unmeasured actionable view matches its open-work count and excludes settled unknown history', function () {
    $expected = [$this->fixtures['unmeasured']->id, $this->fixtures['partial']->id];
    sort($expected);
    $this->actingAs($this->agent)->get('/it?tab=tickets&view=unmeasured')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.view', 'unmeasured')
            ->where('summary.tickets.views.unmeasured', 2)->where('tickets.total', 2)
            ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === $expected));
});

test('a stopped SLA watchdog becomes stale in the hub and reports then recovers', function () {
    $run = ItAutomationRun::query()->create([
        'automation_key' => 'it.check-sla', 'status' => 'succeeded',
        'started_at' => now()->startOfHour(), 'finished_at' => now()->startOfHour()->addMinute(),
    ]);
    $this->actingAs($this->agent)->getJson('/it/reports/data')->assertOk()->assertJsonPath('kpis.sla_watchdog.state', 'fresh');
    $this->travel(61)->minutes();
    $this->get('/it')->assertInertia(fn (Assert $page) => $page->where('summary.tickets.sla_watchdog.state', 'stale'));
    $this->getJson('/it/reports/data')->assertOk()->assertJsonPath('kpis.sla_watchdog.state', 'stale');
    $run->update(['started_at' => now()->startOfHour(), 'finished_at' => now()]);
    $this->getJson('/it/reports/data')->assertOk()->assertJsonPath('kpis.sla_watchdog.state', 'fresh');
});

test('requesters receive their own clock evidence without operational aggregates', function () {
    $this->actingAs($this->worker)->getJson('/it/tickets/'.$this->fixtures['paused']->id)
        ->assertOk()->assertJsonPath('ticket.sla.clocks.first_response.state', 'met')
        ->assertJsonPath('ticket.sla.clocks.resolution.state', 'paused');
    $this->get('/it')->assertInertia(fn (Assert $page) => $page->missing('summary.tickets')->missing('overview'));
    $this->getJson('/it/reports/data')->assertForbidden();
});
