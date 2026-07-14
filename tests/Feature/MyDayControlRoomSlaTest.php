<?php

use App\Models\AuditLog;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;

it('residual terminal SLA is omitted from the My Day alert task', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create();
    AlertSla::query()->create([
        'alert_id' => $alert->id,
        'ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH,
        'cycle_history' => [['ended_as' => AlertSla::ENDED_RECONCILED_NO_MATCH]],
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tasks.0.id', 'alert-'.$alert->id)
            ->where('tasks.0.due_at', null)
            ->where('tasks.0.meta.sla_status', null)
        );
});

it('acknowledges an assigned alert through the canonical lifecycle', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create();
    $definition = SlaDefinition::query()->create([
        'name' => 'My Day acknowledgement',
        'code' => 'my-day-acknowledgement',
        'acknowledge_target_minutes' => 15,
        'response_target_minutes' => 30,
        'resolution_target_minutes' => 120,
        'is_active' => true,
    ]);
    $sla = AlertSla::createFromDefinition($alert, $definition);

    $this->actingAs($worker)
        ->from('/my-day')
        ->post(route('my-day.alert.ack', $alert, false))
        ->assertRedirect('/my-day')
        ->assertSessionHasNoErrors();

    $alert->refresh();
    expect($alert->status)->toBe(ControlRoomAlert::STATUS_ACK)
        ->and($alert->acknowledged_by_user_id)->toBe($worker->id)
        ->and($sla->fresh()->acknowledged_at)->not->toBeNull();

    $audit = AuditLog::query()
        ->where('action', 'controlRoom.alert.acknowledge')
        ->where('auditable_type', $alert->getMorphClass())
        ->where('auditable_id', $alert->id)
        ->latest('id')
        ->firstOrFail();
    expect(data_get($audit->meta, 'from_status'))->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(data_get($audit->meta, 'to_status'))->toBe(ControlRoomAlert::STATUS_ACK);
});

it('reports an invalid My Day acknowledgement without overwriting triage', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $alert = ControlRoomAlert::factory()->triaging()->assignedTo($worker)->create();
    $acknowledgedAt = $alert->acknowledged_at;
    $acknowledgedBy = $alert->acknowledged_by_user_id;

    $this->actingAs($worker)
        ->from('/my-day')
        ->post(route('my-day.alert.ack', $alert, false))
        ->assertRedirect('/my-day')
        ->assertSessionHasErrors('alert');

    $alert->refresh();
    expect($alert->status)->toBe(ControlRoomAlert::STATUS_TRIAGING)
        ->and($alert->acknowledged_at?->equalTo($acknowledgedAt))->toBeTrue()
        ->and($alert->acknowledged_by_user_id)->toBe($acknowledgedBy);
});

it('rechecks the My Day assignee under the lifecycle lock before acknowledging', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $replacement = User::factory()->frontlineWorker()->create();
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create();
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = \Mockery::mock(ControlRoomAlertLifecycleService::class);
    $proxy->shouldReceive('acknowledge')
        ->once()
        ->andReturnUsing(function (...$arguments) use ($alert, $realLifecycle, $replacement) {
            $alert->forceFill(['assigned_to_user_id' => $replacement->id])->save();

            return $realLifecycle->acknowledge(...$arguments);
        });
    $this->app->instance(ControlRoomAlertLifecycleService::class, $proxy);

    $this->actingAs($worker)
        ->from('/my-day')
        ->post(route('my-day.alert.ack', $alert, false))
        ->assertRedirect('/my-day')
        ->assertSessionHasErrors('alert');

    expect($alert->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($alert->fresh()->assigned_to_user_id)->toBe($replacement->id)
        ->and($alert->fresh()->acknowledged_at)->toBeNull();
});

it('rechecks the My Day assignee under a lock before snoozing', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $replacement = User::factory()->frontlineWorker()->create();
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['severity' => 'medium']);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = \Mockery::mock(ControlRoomAlertLifecycleService::class);
    $proxy->shouldReceive('snoozeForAssignee')
        ->once()
        ->andReturnUsing(function (...$arguments) use ($alert, $realLifecycle, $replacement) {
            $alert->forceFill(['assigned_to_user_id' => $replacement->id])->save();

            return $realLifecycle->snoozeForAssignee(...$arguments);
        });
    $this->app->instance(ControlRoomAlertLifecycleService::class, $proxy);

    $this->actingAs($worker)
        ->from('/my-day')
        ->post(route('my-day.alert.snooze', $alert, false), ['window' => '15m'])
        ->assertRedirect('/my-day')
        ->assertSessionHasErrors('alert');

    expect($alert->fresh()->assigned_to_user_id)->toBe($replacement->id)
        ->and($alert->fresh()->snoozed_until)->toBeNull()
        ->and($alert->fresh()->snoozed_by_user_id)->toBeNull();
});
