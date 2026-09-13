<?php

use App\Domain\It\Presenters\ItOverviewWorkboardPresenter;
use App\Domain\It\Services\ItSlaClockService;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-09T02:10:00Z'));
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    foreach (['agent' => 'hr', 'worker' => 'support_worker'] as $key => $role) {
        $this->{$key} = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $this->{$key}->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        ensureCanonicalHrStaffProfile($this->{$key}, $this->site);
    }
    Notification::fake();
    $policy = (new ItSlaPolicy)->forceFill(['first_response_minutes' => 60, 'resolution_minutes' => 240]);
    $this->snapshot = app(ItSlaClockService::class)->policySnapshot('normal', $policy, now()->subHours(2));
});

function overviewRecord($test, array $attributes = []): ItTicket
{
    return ItTicket::factory()->create([
        'site_id' => $test->site->id, 'requester_user_id' => $test->worker->id,
        'title' => 'Permitted overview ticket', 'description' => 'Private body not needed by overview',
        'status' => 'open', 'priority' => 'normal', 'assigned_to_user_id' => null,
        'created_at' => now()->subHours(2), 'first_responded_at' => now()->subMinutes(90),
        'first_response_due_at' => now()->subHour(), 'resolution_due_at' => now()->addHours(2),
        'sla_policy_snapshot' => $test->snapshot, 'sla_state' => 'ok',
        ...$attributes,
    ]);
}

test('overview has complete scoped totals and bounded previews for every priority without leaking other Sites or private content', function () {
    for ($i = 0; $i < 8; $i++) {
        overviewRecord($this, ['priority' => 'urgent']);
    }
    $low = overviewRecord($this, ['priority' => 'low', 'created_at' => now()->subMinute()]);
    $hidden = overviewRecord($this, ['site_id' => Site::factory()->create()->id, 'requester_user_id' => User::factory()->create()->id,
        'title' => 'Hidden outside Site subject', 'is_organisation_wide' => false, 'is_sensitive' => false]);
    $board = app(ItOverviewWorkboardPresenter::class)->present($this->agent);
    expect($board['scopes']['all']['queues']['all_open']['total'])->toBe(9)
        ->and($board['scopes']['all']['queues']['all_open']['ids'])->toHaveCount(6)
        ->and($board['scopes']['urgent']['queues']['all_open']['total'])->toBe(8)
        ->and($board['scopes']['low']['queues']['all_open']['ids'])->toBe([$low->id])
        ->and($board['tickets']->has($hidden->id))->toBeFalse()
        ->and(json_encode($board))->not->toContain('Hidden outside Site subject', 'Private body not needed by overview');
    $this->actingAs($this->agent)->get('/it?tab=overview')->assertOk()->assertInertia(fn ($page) => $page
        ->where('overview.workboard.scopes.all.queues.all_open.total', 9)
        ->where('overview.workboard.scopes.low.queues.all_open.ids.0', $low->id));
    $this->actingAs($this->worker)->get('/it')->assertOk()->assertInertia(fn ($page) => $page->missing('overview'));
});

test('overview picks the missed first response before risk and a different urgent assignment with an evidenced ageing follow-up', function () {
    $overdue = overviewRecord($this, ['priority' => 'urgent', 'first_responded_at' => null,
        'created_at' => now()->subMinutes(92), 'first_response_due_at' => now()->subMinutes(32)]);
    $risk = overviewRecord($this, ['priority' => 'urgent', 'first_responded_at' => null,
        'created_at' => now()->subMinutes(48), 'first_response_due_at' => now()->addMinutes(12)]);
    $vendor = overviewRecord($this, ['created_at' => now()->subDays(16), 'assigned_to_user_id' => $this->agent->id,
        'status' => 'waiting', 'waiting_party' => 'vendor', 'next_response_party' => 'requester']);
    $followup = overviewRecord($this, ['created_at' => now()->subDays(8), 'assigned_to_user_id' => $this->agent->id,
        'next_response_party' => 'it', 'resolution_due_at' => now()->addMinutes(20)]);
    $board = app(ItOverviewWorkboardPresenter::class)->present($this->agent);
    expect($board['scopes']['all']['actions'])->toBe(['response' => $overdue->id, 'assignment' => $risk->id, 'follow_up' => $followup->id])
        ->and($board['tickets'][$overdue->id]['sla']['clocks']['first_response']['state'])->toBe('breached')
        ->and($board['tickets'][$risk->id]['sla']['clocks']['first_response']['state'])->toBe('at_risk')
        ->and($board['tickets'][$followup->id]['conversation']['next_response_party'])->toBe('it')
        ->and($board['tickets'][$followup->id]['sla']['clocks']['resolution']['due_at'])->not->toBeNull()
        ->and($board['scopes']['all']['queues']['aging']['ids'][0])->toBe($vendor->id)
        ->and($overdue->fresh()->sla_state)->toBe('ok');
});

test('overview keeps first reply public responsibility and operational waiting queues distinct under canonical work scope', function () {
    $vendor = overviewRecord($this, ['status' => 'waiting', 'waiting_party' => 'vendor', 'next_response_party' => 'it']);
    $requester = overviewRecord($this, ['status' => 'waiting', 'waiting_party' => 'requester', 'next_response_party' => 'requester']);
    $first = overviewRecord($this, ['first_responded_at' => null, 'next_response_party' => null]);
    $participantOnly = overviewRecord($this, ['site_id' => Site::factory()->create()->id,
        'requester_user_id' => $this->agent->id, 'status' => 'waiting', 'waiting_party' => 'requester',
        'next_response_party' => 'it', 'is_organisation_wide' => false, 'is_sensitive' => true]);
    $board = app(ItOverviewWorkboardPresenter::class)->present($this->agent);
    expect($board['scopes']['all']['queues']['awaiting_reply']['ids'])->toBe([$first->id])
        ->and($board['scopes']['all']['queues']['awaiting_it']['total'])->toBe(2)
        ->and($board['scopes']['all']['queues']['waiting_requester']['ids'])->toBe([$requester->id])
        ->and($board['scopes']['all']['queues']['waiting']['total'])->toBe(3)
        ->and($board['tickets'][$participantOnly->id]['can_manage'])->toBeFalse()
        ->and($board['tickets'][$participantOnly->id]['waiting_party'])->toBeNull()
        ->and($board['tickets'][$vendor->id]['conversation']['state'])->toBe('awaiting_it');
});

test('overview reflects canonical assignment and keeps missing SLA evidence separate from a healthy result', function () {
    $ticket = overviewRecord($this, ['sla_policy_snapshot' => null, 'first_response_due_at' => null,
        'resolution_due_at' => null, 'first_responded_at' => null]);
    $before = app(ItOverviewWorkboardPresenter::class)->present($this->agent);
    expect($before['scopes']['all']['queues']['unassigned']['total'])->toBe(1)
        ->and($before['scopes']['all']['queues']['unmeasured']['total'])->toBe(1)
        ->and($before['scopes']['all']['queues']['attention']['total'])->toBe(0)
        ->and($before['unmeasured_total'])->toBe(1);
    $ticket->update(['assigned_to_user_id' => $this->agent->id, 'lock_version' => 2]);
    $after = app(ItOverviewWorkboardPresenter::class)->present($this->agent);
    expect($after['scopes']['all']['queues']['unassigned']['total'])->toBe(0)
        ->and($after['scopes']['all']['queues']['mine']['ids'])->toBe([$ticket->id])
        ->and($after['tickets'][$ticket->id]['lock_version'])->toBe(2)
        ->and($after['tickets'][$ticket->id]['sla']['state'])->toBe('unmeasured');
});
