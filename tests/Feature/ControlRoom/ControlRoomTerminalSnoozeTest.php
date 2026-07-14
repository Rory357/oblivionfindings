<?php

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('clears a snooze when a human resolves an alert', function () {
    $actor = User::factory()->create();
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'snoozed_until' => now()->addHour(),
        'snoozed_by_user_id' => $actor->id,
    ]);

    app(ControlRoomAlertLifecycleService::class)->resolve(
        $alert,
        $actor,
        'The immediate response is complete.',
        'controlled',
    );

    expect($alert->fresh()->snoozed_until)->toBeNull()
        ->and($alert->fresh()->snoozed_by_user_id)->toBeNull();
});

it('clears a snooze when a sensor alert is dismissed', function () {
    $actor = User::factory()->create();
    $alert = ControlRoomAlert::factory()->open()->create([
        'source' => 'sensor',
        'snoozed_until' => now()->addHour(),
        'snoozed_by_user_id' => $actor->id,
    ]);

    app(ControlRoomAlertLifecycleService::class)->dismissSensor($alert, $actor, 'Confirmed false alarm.');

    expect($alert->fresh()->snoozed_until)->toBeNull()
        ->and($alert->fresh()->snoozed_by_user_id)->toBeNull();
});

it('clears a snooze during automated resolution', function () {
    $actor = User::factory()->create();
    $alert = ControlRoomAlert::factory()->open()->create([
        'snoozed_until' => now()->addHour(),
        'snoozed_by_user_id' => $actor->id,
    ]);

    app(ControlRoomAlertLifecycleService::class)->resolveAutomatically(
        $alert,
        'The source workflow verified that no further response is required.',
        'source_cleared',
        'test',
    );

    expect($alert->fresh()->snoozed_until)->toBeNull()
        ->and($alert->fresh()->snoozed_by_user_id)->toBeNull();
});

it('never treats a terminal alert with legacy snooze fields as snoozed work', function () {
    $actor = User::factory()->create();
    $terminal = ControlRoomAlert::factory()->resolved()->create([
        'snoozed_until' => now()->addHour(),
        'snoozed_by_user_id' => $actor->id,
    ]);

    expect(ControlRoomAlert::query()->snoozed()->whereKey($terminal->id)->exists())->toBeFalse();
});
