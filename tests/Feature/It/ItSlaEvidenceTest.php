<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItSlaClockService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\ItAutomationRun;
use App\Models\ItEmailDelivery;
use App\Models\ItQueue;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketSlaNotification;
use App\Support\It\BusinessHours;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

function slaEvidenceUser(string $role, Site $site): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()->subMonth()]);
    $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user;
}

function slaEvidenceTicket(array $attributes = []): ItTicket
{
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => test()->worker->id, 'site_id' => test()->site->id,
        'assigned_to_user_id' => test()->agent->id, 'owner_user_id' => test()->agent->id,
        'work_type' => 'incident', 'workflow_state' => 'submitted', 'status' => 'open',
        'priority' => 'urgent', 'created_at' => now(), ...$attributes,
    ]);
    $ticket->stampSlaDueDates();
    $ticket->save();

    return $ticket->refresh();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 08:00', 'Pacific/Auckland')->utc());
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->worker = slaEvidenceUser('support_worker', $this->site);
    $this->agent = slaEvidenceUser('hr', $this->site);
    Notification::fake();
});

test('priority restamping keeps original configured policy and an already missed clock', function () {
    $policy = ItSlaPolicy::query()->create(['priority' => 'high', 'first_response_minutes' => 30, 'resolution_minutes' => 90]);
    $ticket = slaEvidenceTicket(['priority' => 'high']);
    $original = $ticket->sla_original_policy_snapshot;
    $oldDue = $ticket->first_response_due_at->copy();
    $policy->update(['first_response_minutes' => 5, 'resolution_minutes' => 15]);
    $this->travel(35)->minutes();
    expect(app(ItSlaClockService::class)->verdict($ticket, now())['clocks']['resolution']['state'])->toBe('ok');
    $ticket->priority = 'urgent';
    $ticket->stampSlaDueDates();
    $ticket->save();
    $ticket->refresh();
    expect($ticket->sla_original_policy_snapshot)->toBe($original)
        ->and($ticket->sla_policy_snapshot['first_response_minutes'])->toBe(60)
        ->and($ticket->first_response_breached_at->equalTo($oldDue))->toBeTrue()
        ->and($ticket->sla_state)->toBe('breached')
        ->and($ticket->events()->where('type', 'sla_policy_changed')->value('payload')['previous_policy'])->toBe($original);
});

test('reprioritizing an existing unstamped record cannot invent historical SLA clocks', function () {
    $ticket = ItTicket::factory()->create(['created_at' => now()->subDays(5)])->fresh();
    $ticket->priority = 'urgent';
    $ticket->stampSlaDueDates();
    $ticket->save();
    expect($ticket->fresh()->first_response_due_at)->toBeNull()
        ->and($ticket->fresh()->resolution_due_at)->toBeNull()
        ->and($ticket->fresh()->sla_original_policy_snapshot)->toBeNull()
        ->and($ticket->fresh()->sla_state)->toBe('unmeasured');
});

test('public reply and resolution preserve a late response without manufacturing response from internal work', function () {
    $ticket = slaEvidenceTicket();
    $this->travel(61)->minutes();
    $interactions = app(ItTicketInteractionService::class);
    $interactions->addComment($ticket, $this->agent, 'Synthetic internal investigation.', true);
    expect($ticket->fresh()->first_responded_at)->toBeNull();
    $interactions->addComment($ticket, $this->agent, 'Synthetic public response.', false);
    $ticket->refresh();
    expect($ticket->sla_state)->toBe('breached')->and($ticket->first_response_breached_at)->not->toBeNull();
    $resolved = $interactions->resolveWithPublicNote($ticket, $this->agent, 'Synthetic repair completed.', resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic service restoration confirmed.']);
    $verdict = app(ItSlaClockService::class)->verdict($resolved, now());
    expect($verdict['state'])->toBe('breached')
        ->and($verdict['clocks']['first_response']['state'])->toBe('breached')
        ->and($verdict['clocks']['resolution']['state'])->toBe('met');
    $reopened = $interactions->reopenWithReason($resolved, $this->agent, 'Synthetic issue returned.')['ticket'];
    expect($reopened->first_response_breached_at->equalTo($ticket->first_response_breached_at))->toBeTrue()
        ->and(app(ItSlaClockService::class)->verdict($reopened, now())['clocks']['resolution']['reason'])->toBe('reopen_clock_policy_required');
});

test('banked pauses use the recorded calendar across a holiday and NZ daylight saving', function () {
    $start = CarbonImmutable::parse('2026-09-25 15:00', 'Pacific/Auckland');
    $this->travelTo($start->utc());
    ItSlaPolicy::query()->create([
        'priority' => 'urgent', 'first_response_minutes' => 60, 'resolution_minutes' => 120,
        ...BusinessHours::nzDefault(), 'holiday_dates' => ['2026-09-28'],
    ]);
    $ticket = slaEvidenceTicket(['first_responded_at' => now()]);
    $this->travelTo($start->addHour()->utc());
    $ticket->startWaiting();
    $ticket->save();
    $this->travelTo(CarbonImmutable::parse('2026-09-29 09:00', 'Pacific/Auckland')->utc());
    $ticket->refresh()->stopWaiting();
    $ticket->save();
    $verdict = app(ItSlaClockService::class)->verdict($ticket->fresh(), now());
    expect($ticket->sla_paused_minutes)->toBe(120)
        ->and($verdict['clocks']['resolution']['due_at'])->toBe(CarbonImmutable::parse('2026-09-29 10:00', 'Pacific/Auckland')->utc()->toIso8601String())
        ->and($verdict['clocks']['resolution']['state'])->toBe('ok');
});

test('changing priority during a live wait banks the old calendar before starting the new one', function () {
    $start = CarbonImmutable::parse('2026-09-25 15:00', 'Pacific/Auckland');
    $this->travelTo($start->utc());
    ItSlaPolicy::query()->create([
        'priority' => 'normal', 'first_response_minutes' => 60, 'resolution_minutes' => 120,
        ...BusinessHours::nzDefault(),
    ]);
    $ticket = slaEvidenceTicket(['priority' => 'normal', 'first_responded_at' => now()]);
    $this->travelTo($start->addHour()->utc());
    $ticket->startWaiting();
    $ticket->save();
    $changedAt = CarbonImmutable::parse('2026-09-28 09:00', 'Pacific/Auckland')->utc();
    $this->travelTo($changedAt);
    $ticket->priority = 'urgent';
    $ticket->stampSlaDueDates();
    $ticket->save();
    expect($ticket->fresh()->sla_paused_minutes)->toBe(120)
        ->and($ticket->fresh()->waiting_since->equalTo($changedAt))->toBeTrue()
        ->and($ticket->fresh()->sla_policy_snapshot['calendar'])->toBeNull()
        ->and($ticket->fresh()->sla_original_policy_snapshot['calendar'])->not->toBeNull();
    $this->travel(30)->minutes();
    $ticket->stopWaiting();
    $ticket->save();
    expect($ticket->fresh()->sla_paused_minutes)->toBe(150);
});

test('an invalid historical pause calendar does not trap work or invent elapsed compliance', function () {
    $ticket = slaEvidenceTicket();
    $snapshot = $ticket->sla_policy_snapshot;
    $snapshot['calendar'] = ['business_hours' => ['mon' => [['17:00', '08:00']]]];
    $ticket->forceFill(['status' => 'waiting', 'waiting_since' => now()->subMinutes(30), 'sla_policy_snapshot' => $snapshot])->save();
    $ticket->stopWaiting();
    $ticket->save();
    expect($ticket->fresh()->status)->toBe('in_progress')
        ->and($ticket->fresh()->waiting_since)->toBeNull()
        ->and($ticket->fresh()->sla_paused_minutes)->toBe(0)
        ->and($ticket->fresh()->sla_policy_snapshot['pause_unit'])->toBe('legacy_unknown')
        ->and($ticket->fresh()->sla_state)->toBe('unmeasured');
});

test('a new approval wait records its actual policy instead of inheriting a historical gap', function () {
    $ticket = slaEvidenceTicket([
        'status' => 'waiting', 'workflow_state' => 'approval_pending',
        'waiting_since' => now(), 'waiting_party' => 'approver',
        'waiting_reason' => 'Synthetic approval required.', 'first_responded_at' => now(),
    ]);
    expect($ticket->sla_state)->toBe('paused')
        ->and($ticket->sla_policy_snapshot['pause_unit'])->toBe('business_minutes')
        ->and($ticket->sla_original_policy_snapshot)->toBe($ticket->sla_policy_snapshot);
    $this->travel(30)->minutes();
    $ticket->stopWaiting();
    $ticket->save();
    expect($ticket->fresh()->sla_paused_minutes)->toBe(30);
});

test('watchdog emits independent response and resolution breaches once and records a successful run', function () {
    $ticket = slaEvidenceTicket();
    $this->travel(61)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();
    $this->travel(180)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();
    $this->artisan('it:check-sla')->assertSuccessful();
    expect($ticket->events()->where('type', 'sla_breached')->count())->toBe(2)
        ->and($ticket->events()->where('type', 'sla_breached')->pluck('payload')->pluck('clock')->sort()->values()->all())->toBe(['first_response', 'resolution'])
        ->and(ItAutomationRun::query()->where('automation_key', 'it.check-sla')->where('status', 'succeeded')->count())->toBe(3)
        ->and($ticket->fresh()->sla_checked_at)->not->toBeNull();
    Notification::assertSentToTimes($this->agent, TicketSlaNotification::class, 2);
    $task = collect(app(Schedule::class)->events())->firstWhere('description', 'it.check-sla');
    app(ItAutomationRunRecorder::class)->starting(new ScheduledTaskStarting($task));
    app(ItAutomationRunRecorder::class)->finished(new ScheduledTaskFinished($task, 0.1));
    expect(ItAutomationRun::query()->where('automation_key', 'it.check-sla')->count())->toBe(3);
});

test('watchdog notification intent failure rolls back its state event and intent together', function () {
    $ticket = slaEvidenceTicket();
    $this->travel(61)->minutes();
    $this->mock(ItEmailDeliveryService::class)->shouldReceive('prepare')->once()->andThrow(new RuntimeException('Synthetic intent failure.'));
    expect(fn () => $this->artisan('it:check-sla')->run())->toThrow(RuntimeException::class);
    expect($ticket->fresh()->sla_state)->toBe('ok')
        ->and($ticket->fresh()->first_response_breached_at)->toBeNull()
        ->and($ticket->events()->where('type', 'sla_breached')->count())->toBe(0)
        ->and(ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count())->toBe(0)
        ->and(ItAutomationRun::query()->where('automation_key', 'it.check-sla')->latest('id')->value('status'))->toBe('failed');
});

test('unavailable accountable owner escalates only to current eligible queue cover', function () {
    $cover = slaEvidenceUser('hr', $this->site);
    $queue = ItQueue::query()->create(['name' => 'Synthetic SLA cover', 'key' => 'synthetic-sla-cover', 'filter_rules' => ['cover_user_id' => $cover->id], 'is_active' => true]);
    HrLeaveRequest::factory()->create([
        'user_id' => $this->agent->id, 'status' => 'approved',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
    ]);
    $ticket = slaEvidenceTicket(['queue_id' => $queue->id]);
    $this->travel(40)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();
    $this->artisan('it:check-sla')->assertSuccessful();
    Notification::assertSentToTimes($cover, TicketSlaNotification::class, 1);
    Notification::assertNotSentTo($this->agent, TicketSlaNotification::class);
    expect($ticket->events()->where('type', 'sla_escalated')->value('payload')['reason'])->toBe('unavailable_owner');
});

test('a missing escalation recipient can recover later without rewriting the original event', function () {
    $ticket = slaEvidenceTicket(['assigned_to_user_id' => null, 'owner_user_id' => null]);
    $this->travel(40)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();
    $event = $ticket->events()->where('type', 'sla_escalated')->firstOrFail();
    $payload = $event->payload;
    expect($payload['coverage_gap'])->toBeTrue();
    $admin = slaEvidenceUser('admin', $this->site);
    $this->artisan('it:check-sla')->assertSuccessful();
    $this->artisan('it:check-sla')->assertSuccessful();
    expect($event->fresh()->payload)->toBe($payload)
        ->and($ticket->events()->where('type', 'sla_escalation_coverage_restored')->count())->toBe(1);
    Notification::assertSentToTimes($admin, TicketSlaNotification::class, 1);
});

test('deferred SLA alert rechecks staff eligibility even when a revoked technician remains a requester', function () {
    $ticket = slaEvidenceTicket(['requester_user_id' => $this->agent->id]);
    $deliveries = app(ItEmailDeliveryService::class);
    $deliveries->prepare($this->agent, new TicketSlaNotification($ticket, 'at_risk', 'first_response'));
    $this->agent->roles()->sync([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
    $this->agent->forceFill(['role' => 'support_worker'])->save();
    $deliveries->dispatchPending(100, $ticket->id);
    Notification::assertNothingSent();
    $delivery = ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->sole();
    expect($delivery->status)->toBe('failed')->and($delivery->sending_at)->toBeNull()
        ->and($delivery->last_error)->toContain('no longer has access');
});
