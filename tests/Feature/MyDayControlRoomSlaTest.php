<?php

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoomAlert;
use App\Models\User;

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
