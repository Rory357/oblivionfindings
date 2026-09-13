<?php

use App\Domain\It\Services\ItRecurrenceService;
use App\Models\ItRecurrencePlan;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->agent->roles()->syncWithoutDetaching(Role::where('name', 'hr')->pluck('id'));
    ensureCanonicalHrStaffProfile($this->agent, $this->site);
    $this->requester = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->requester->roles()->syncWithoutDetaching(Role::where('name', 'support_worker')->pluck('id'));
    $this->recurrence = app(ItRecurrenceService::class);
});

function recurrencePlanData($test, array $replace = []): array
{
    return array_replace_recursive([
        'name' => 'Monthly printer service',
        'cron_expression' => '0 9 1 * *',
        'timezone' => 'Pacific/Auckland',
        'starts_on' => '2026-01-01',
        'ticket_template' => [
            'title' => 'Service the office printers',
            'description' => 'Synthetic recurring maintenance fixture.',
            'site_id' => $test->site->id,
            'category' => 'hardware',
            'work_type' => 'task',
            'priority' => 'normal',
        ],
    ], $replace);
}

test('managing agents create update and version plans while stale or invalid edits are rejected', function () {
    $this->actingAs($this->agent)->post('/it/setup/recurrence-plans', recurrencePlanData($this))
        ->assertRedirect()->assertSessionHas('success');
    $plan = ItRecurrencePlan::query()->sole();
    expect($plan->lock_version)->toBe(1)
        ->and($plan->status)->toBe('active')
        ->and($plan->next_due_at)->not->toBeNull()
        ->and($plan->owner_user_id)->toBe($this->agent->id);

    $this->actingAs($this->agent)->patch("/it/setup/recurrence-plans/{$plan->id}", recurrencePlanData($this, [
        'name' => 'Quarterly printer service', 'lock_version' => 1,
    ]))->assertRedirect()->assertSessionHas('success');
    expect($plan->fresh()->lock_version)->toBe(2);

    $this->actingAs($this->agent)->patch("/it/setup/recurrence-plans/{$plan->id}", recurrencePlanData($this, [
        'name' => 'Stale rename', 'lock_version' => 1,
    ]))->assertSessionHasErrors('lock_version');

    $this->actingAs($this->agent)->post('/it/setup/recurrence-plans', recurrencePlanData($this, [
        'cron_expression' => 'not a schedule',
    ]))->assertSessionHasErrors('cron_expression');

    $this->actingAs($this->requester)->post('/it/setup/recurrence-plans', recurrencePlanData($this))
        ->assertForbidden();
});

test('a due occurrence creates exactly one routed ticket and replays cannot double-create', function () {
    $plan = $this->recurrence->create($this->agent, recurrencePlanData($this, [
        'cron_expression' => '0 9 * * *',
    ]));
    $due = CarbonImmutable::parse($plan->next_due_at)->addMinute();

    $first = $this->recurrence->runDue($due);
    expect($first['created'])->toBe(1)->and($first['failed'])->toBe(0);
    $ticket = ItTicket::query()->sole();
    expect($ticket->source)->toBe('system')
        ->and($ticket->status_reason)->toBe('recurring_plan')
        ->and($ticket->requester_user_id)->toBe($this->agent->id)
        ->and($ticket->site_id)->toBe($this->site->id)
        ->and($ticket->first_response_due_at)->not->toBeNull()
        ->and($ticket->events()->where('type', 'created_from_recurrence')->exists())->toBeTrue();

    // A crashed-and-replayed scheduler run cannot create the ticket again.
    $replay = $this->recurrence->runDue($due);
    expect($replay['created'])->toBe(0)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and($plan->runs()->where('status', 'created')->count())->toBe(1);
    expect(CarbonImmutable::parse($plan->fresh()->next_due_at)->gt($due))->toBeTrue();
});

test('missed occurrences are recorded as bounded skips and only the latest becomes work', function () {
    $plan = $this->recurrence->create($this->agent, recurrencePlanData($this, [
        'cron_expression' => '0 9 * * *',
    ]));
    $now = CarbonImmutable::parse($plan->next_due_at)->addDays(4)->addMinute();

    $summary = $this->recurrence->runDue($now);
    expect($summary['created'])->toBe(1)
        ->and($summary['skipped'])->toBe(4)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and($plan->runs()->where('status', 'skipped')->count())->toBe(4);
});

test('paused and retired plans create nothing and retirement is terminal', function () {
    $plan = $this->recurrence->create($this->agent, recurrencePlanData($this, [
        'cron_expression' => '0 9 * * *',
    ]));
    $due = CarbonImmutable::parse($plan->next_due_at)->addMinute();

    $this->actingAs($this->agent)->post("/it/setup/recurrence-plans/{$plan->id}/status", [
        'status' => 'paused', 'lock_version' => 1,
    ])->assertRedirect()->assertSessionHas('success');
    expect($this->recurrence->runDue($due)['created'])->toBe(0)
        ->and(ItTicket::query()->count())->toBe(0)
        ->and($plan->fresh()->next_due_at)->toBeNull();

    $this->actingAs($this->agent)->post("/it/setup/recurrence-plans/{$plan->id}/status", [
        'status' => 'retired', 'lock_version' => 2,
    ])->assertRedirect();
    $this->actingAs($this->agent)->post("/it/setup/recurrence-plans/{$plan->id}/status", [
        'status' => 'active', 'lock_version' => 3,
    ])->assertSessionHasErrors('status');
    expect($plan->fresh()->status)->toBe('retired');
});

test('exception dates are skipped and an end date retires the plan after its last occurrence', function () {
    $start = CarbonImmutable::now('Pacific/Auckland')->addDay();
    $plan = $this->recurrence->create($this->agent, recurrencePlanData($this, [
        'cron_expression' => '0 9 * * *',
        'starts_on' => $start->toDateString(),
        'ends_on' => $start->addDays(1)->toDateString(),
        'exception_dates' => [$start->toDateString()],
    ]));

    // The excepted first day is skipped; the next due lands on the end date.
    $next = CarbonImmutable::parse($plan->next_due_at)->setTimezone('Pacific/Auckland');
    expect($next->toDateString())->toBe($start->addDays(1)->toDateString());

    $summary = $this->recurrence->runDue(CarbonImmutable::parse($plan->next_due_at)->addMinute());
    expect($summary['created'])->toBe(1)
        ->and($plan->fresh()->status)->toBe('retired')
        ->and($plan->fresh()->next_due_at)->toBeNull();
});
