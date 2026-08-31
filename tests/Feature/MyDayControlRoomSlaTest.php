<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;

function myDayAlertWorkerAt(Site $site): User
{
    $worker = User::factory()->frontlineWorker()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
    ]);

    return $worker;
}

it('conceals assigned alerts outside the workers current approved Sites', function () {
    $localSite = Site::factory()->create(['is_active' => true]);
    $remoteSite = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($localSite);
    $localAlert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $localSite->id,
    ]);
    $remoteAlert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $remoteSite->id,
    ]);

    $response = $this->actingAs($worker)->get('/my-day')->assertOk();
    $taskIds = collect($response->inertiaProps('tasks'))->pluck('id')->all();

    expect($taskIds)
        ->toContain('alert-'.$localAlert->id)
        ->not->toContain('alert-'.$remoteAlert->id);
});

it('forbids an assigned worker from acknowledging or snoozing a remote Site alert', function () {
    $localSite = Site::factory()->create(['is_active' => true]);
    $remoteSite = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($localSite);
    $ackAlert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $remoteSite->id,
    ]);
    $snoozeAlert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $remoteSite->id,
        'severity' => 'medium',
    ]);

    $this->actingAs($worker)
        ->post(route('my-day.alert.ack', $ackAlert, false))
        ->assertForbidden();
    $this->post(route('my-day.alert.snooze', $snoozeAlert, false), ['window' => '15m'])
        ->assertForbidden();

    expect($ackAlert->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($ackAlert->fresh()->acknowledged_at)->toBeNull()
        ->and($snoozeAlert->fresh()->snoozed_until)->toBeNull()
        ->and($snoozeAlert->fresh()->snoozed_by_user_id)->toBeNull();
});

it('conceals and protects an assigned alert after the workers Site access is revoked', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['site_id' => $site->id]);
    $worker->hrEmployeeProfile()->update(['is_active' => false]);

    $response = $this->actingAs($worker)->get('/my-day')->assertOk();
    expect(collect($response->inertiaProps('tasks'))->pluck('id')->all())
        ->not->toContain('alert-'.$alert->id);

    $this->post(route('my-day.alert.ack', $alert, false))->assertForbidden();
    expect($alert->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($alert->fresh()->acknowledged_at)->toBeNull();
});

it('residual terminal SLA is omitted from the My Day alert task', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['site_id' => $site->id]);
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
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['site_id' => $site->id]);
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
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->triaging()->assignedTo($worker)->create(['site_id' => $site->id]);
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
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $replacement = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['site_id' => $site->id]);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = Mockery::mock(ControlRoomAlertLifecycleService::class);
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
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $replacement = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $site->id,
        'severity' => 'medium',
    ]);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = Mockery::mock(ControlRoomAlertLifecycleService::class);
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

it('rechecks canonical Site access under the lifecycle lock before acknowledging', function () {
    $localSite = Site::factory()->create(['is_active' => true]);
    $remoteSite = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($localSite);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['site_id' => $localSite->id]);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = Mockery::mock(ControlRoomAlertLifecycleService::class);
    $proxy->shouldReceive('acknowledge')
        ->once()
        ->andReturnUsing(function (...$arguments) use ($alert, $realLifecycle, $remoteSite) {
            $alert->forceFill(['site_id' => $remoteSite->id])->save();

            return $realLifecycle->acknowledge(...$arguments);
        });
    $this->app->instance(ControlRoomAlertLifecycleService::class, $proxy);

    $this->actingAs($worker)
        ->post(route('my-day.alert.ack', $alert, false))
        ->assertForbidden();

    expect($alert->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($alert->fresh()->acknowledged_at)->toBeNull();
});

it('rechecks canonical Site access under the lifecycle lock before snoozing', function () {
    $localSite = Site::factory()->create(['is_active' => true]);
    $remoteSite = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($localSite);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $localSite->id,
        'severity' => 'medium',
    ]);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = Mockery::mock(ControlRoomAlertLifecycleService::class);
    $proxy->shouldReceive('snoozeForAssignee')
        ->once()
        ->andReturnUsing(function (...$arguments) use ($alert, $realLifecycle, $remoteSite) {
            $alert->forceFill(['site_id' => $remoteSite->id])->save();

            return $realLifecycle->snoozeForAssignee(...$arguments);
        });
    $this->app->instance(ControlRoomAlertLifecycleService::class, $proxy);

    $this->actingAs($worker)
        ->post(route('my-day.alert.snooze', $alert, false), ['window' => '15m'])
        ->assertForbidden();

    expect($alert->fresh()->snoozed_until)->toBeNull()
        ->and($alert->fresh()->snoozed_by_user_id)->toBeNull();
});

it('rechecks current worker access under the lifecycle lock before acknowledging', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create(['site_id' => $site->id]);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = Mockery::mock(ControlRoomAlertLifecycleService::class);
    $proxy->shouldReceive('acknowledge')
        ->once()
        ->andReturnUsing(function (...$arguments) use ($worker, $realLifecycle) {
            $worker->hrEmployeeProfile()->update(['is_active' => false]);

            return $realLifecycle->acknowledge(...$arguments);
        });
    $this->app->instance(ControlRoomAlertLifecycleService::class, $proxy);

    $this->actingAs($worker)
        ->post(route('my-day.alert.ack', $alert, false))
        ->assertForbidden();

    expect($alert->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($alert->fresh()->acknowledged_at)->toBeNull();
});

it('rechecks current worker access under the lifecycle lock before snoozing', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $worker = myDayAlertWorkerAt($site);
    $alert = ControlRoomAlert::factory()->open()->assignedTo($worker)->create([
        'site_id' => $site->id,
        'severity' => 'medium',
    ]);
    $realLifecycle = app(ControlRoomAlertLifecycleService::class);
    $proxy = Mockery::mock(ControlRoomAlertLifecycleService::class);
    $proxy->shouldReceive('snoozeForAssignee')
        ->once()
        ->andReturnUsing(function (...$arguments) use ($worker, $realLifecycle) {
            $worker->hrEmployeeProfile()->update(['is_active' => false]);

            return $realLifecycle->snoozeForAssignee(...$arguments);
        });
    $this->app->instance(ControlRoomAlertLifecycleService::class, $proxy);

    $this->actingAs($worker)
        ->post(route('my-day.alert.snooze', $alert, false), ['window' => '15m'])
        ->assertForbidden();

    expect($alert->fresh()->snoozed_until)->toBeNull()
        ->and($alert->fresh()->snoozed_by_user_id)->toBeNull();
});
