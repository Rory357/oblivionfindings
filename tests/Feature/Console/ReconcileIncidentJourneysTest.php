<?php

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyReconciler;

it('reports without mutation then repairs only deterministic incident journey drift', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $owner = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
        'client_id' => $client->id,
        'site_id' => null,
        'reported_by' => $owner->id,
        'source' => 'manual',
        'is_notifiable' => false,
        'worksafe_notification_status' => null,
        'site_preserved' => false,
    ]));
    $event = HsEvent::factory()->forClientIncident($incident)->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'client_id' => $client->id,
        'status' => HsEvent::STATUS_INVESTIGATING,
        'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        'owner_user_id' => $owner->id,
        'accepted_by_user_id' => null,
        'accepted_at' => null,
        'worksafe_notifiable' => true,
        'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
        'worksafe_reference' => 'WS-441',
        'worksafe_notified_at' => now()->subHour(),
        'worksafe_site_preserved' => true,
    ]);
    $alert = ControlRoomAlert::factory()->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'status' => ControlRoomAlert::STATUS_DISMISSED,
        'context' => ['incident_id' => $incident->id],
    ]);
    $event->forceFill([
        'control_room_alert_id' => $alert->id,
    ])->saveQuietly();
    $alert->forceFill(['reference_number' => null])->saveQuietly();
    $incident->forceFill([
        'reference_number' => null,
        'hs_event_id' => null,
        'control_room_alert_id' => null,
    ])->saveQuietly();
    $task = AlertTask::query()->create([
        'alert_id' => $alert->id,
        'title' => 'Operational work left active',
        'status' => AlertTask::STATUS_IN_PROGRESS,
        'priority' => 'high',
        'created_by_user_id' => $owner->id,
    ]);

    $missingHs = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create([
        'client_id' => $client->id,
        'reported_by' => $owner->id,
        'source' => 'support_worker',
        'severity' => 'medium',
    ]));

    $duplicate = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create([
        'client_id' => $client->id,
        'reported_by' => $owner->id,
        'source' => 'sensor',
        'severity' => 'medium',
    ]));
    ControlRoomAlert::factory()->count(2)->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'context' => ['incident_id' => $duplicate->id],
    ]);

    $reconciler = app(IncidentJourneyReconciler::class);
    $dryRun = $reconciler->reconcile();

    expect($dryRun->issues['link_mismatch'])->toBeGreaterThanOrEqual(1)
        ->and($dryRun->issues['duplicate_alert'])->toBe(1)
        ->and($dryRun->issues['missing_reference'])->toBeGreaterThanOrEqual(1)
        ->and($dryRun->issues['worksafe_drift'])->toBeGreaterThanOrEqual(1)
        ->and($dryRun->issues['missing_site'])->toBeGreaterThanOrEqual(1)
        ->and($dryRun->issues['dismissed_active'])->toBe(1)
        ->and($dryRun->issues['acceptance_backfill'])->toBe(1)
        ->and($dryRun->issues['missing_hs'])->toBe(2)
        ->and($dryRun->totalRepairs())->toBe(0)
        ->and($incident->fresh()->hs_event_id)->toBeNull()
        ->and($task->fresh()->status)->toBe(AlertTask::STATUS_IN_PROGRESS);

    $applied = $reconciler->reconcile(true);

    $incident->refresh();
    $event->refresh();
    expect($applied->hasFatalErrors())->toBeFalse()
        ->and($applied->totalRepairs())->toBeGreaterThanOrEqual(7)
        ->and($incident->site_id)->toBe($site->id)
        ->and($incident->hs_event_id)->toBe($event->id)
        ->and($incident->control_room_alert_id)->toBe($alert->id)
        ->and($incident->reference_number)->not->toBeNull()
        ->and($event->reference_number)->not->toBeNull()
        ->and($alert->fresh()->reference_number)->not->toBeNull()
        ->and($incident->is_notifiable)->toBeTrue()
        ->and($incident->worksafe_notification_status)->toBe(HsEvent::WORKSAFE_NOTIFIED)
        ->and($incident->worksafe_reference)->toBe('WS-441')
        ->and($task->fresh()->status)->toBe(AlertTask::STATUS_CANCELLED)
        ->and($event->handover_status)->toBe(HsEvent::HANDOVER_ACCEPTED)
        ->and($event->accepted_by_user_id)->toBe($owner->id)
        ->and($missingHs->fresh()->hs_event_id)->not->toBeNull()
        ->and($duplicate->fresh()->hs_event_id)->toBeNull();

    $rerun = $reconciler->reconcile(true);
    expect($rerun->hasFatalErrors())->toBeFalse()
        ->and($rerun->totalRepairs())->toBe(0)
        ->and($rerun->issues['duplicate_alert'])->toBe(1);
});

it('registers a dry-run-first command with optional incident scoping', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
    $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create([
        'client_id' => $client->id,
        'severity' => 'medium',
    ]));

    $this->artisan('incidents:reconcile-journeys', [
        '--incident' => $incident->id,
        '--chunk' => 1,
    ])
        ->expectsOutputToContain('Incident journey reconciliation (dry-run)')
        ->expectsOutputToContain('Dry-run only: no records were changed.')
        ->assertSuccessful();

    expect($incident->fresh()->hs_event_id)->toBeNull();
});
