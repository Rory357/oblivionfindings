<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItMonitoringDeliveryService;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Presenters\MonitoringIncidentEvidencePresenter;
use App\Domain\Monitoring\Services\MonitoringAvailabilityEpisode;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use Carbon\CarbonImmutable;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-12T01:00:00Z'));
    $this->seed(SecurityDevicesSignalSeeder::class);
    // Explicit isolated operational-routing fixture for the Control Room + IT cases.
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'high']);
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();
});

afterEach(function () {
    $this->travelBack();
});

/** @return array{Monitor, Site} */
function episodeMonitor(array $profileValues = []): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subDay(),
        'assigned_by_user_id' => User::factory()->create()->id,
    ]);
    $profile = MonitoringProfile::factory()->create([
        'failure_confirmations' => 1, 'recovery_confirmations' => 1,
        'failure_duration_seconds' => 0, 'recovery_duration_seconds' => 0, ...$profileValues,
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id, 'profile_id' => $profile->id, 'collector_id' => null,
        'current_state' => MonitorState::Healthy, 'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);

    return [$monitor, $site];
}

function episodeObservation(Monitor $monitor, Site $site, MonitorState $state, int $second, ?string $key = null, ?int $value = null)
{
    return app(MonitoringObservationIngestor::class)->ingest($monitor,
        new ObservationInput($key ?? 'episode-'.$monitor->id.'-'.$second, $state,
            CarbonImmutable::parse('2026-09-12T01:00:00Z')->addSeconds($second), value: $value),
        $site->id, $monitor->device_id, null);
}

function deliverEpisodeSource(DeviceEvent $event, bool $deliverIt = true): DeviceEventSignalOutbox
{
    $outbox = $event->signalOutbox()->sole();
    app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
    if ($deliverIt) {
        app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    }

    return $outbox->fresh();
}

test('delayed recovery affects only its original episode even when the same monitor fails again', function () {
    [$monitor, $site] = episodeMonitor();
    $first = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    $firstOutbox = deliverEpisodeSource($first);
    $firstTicket = ItTicket::query()->sole();
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    $second = episodeObservation($monitor, $site, MonitorState::Failed, 3)->deviceEvent;
    $secondOutbox = deliverEpisodeSource($second);
    $secondTicket = ItTicket::query()->findOrFail($secondOutbox->it_ticket_ids[0]);
    expect(data_get($first->payload, 'monitor_correlation_key'))->toBe(data_get($second->payload, 'monitor_correlation_key'))
        ->and(MonitoringAvailabilityEpisode::fromEvent($first)['key'])->not->toBe(MonitoringAvailabilityEpisode::fromEvent($second)['key'])
        ->and(ControlRoomAlert::query()->count())->toBe(2)->and(ItTicket::query()->count())->toBe(2);

    deliverEpisodeSource($recovery);
    deliverEpisodeSource($recovery);
    expect($firstTicket->fresh()->monitoring_recovered_at)->not->toBeNull()
        ->and($firstTicket->fresh()->status)->toBe('open')
        ->and($secondTicket->fresh()->monitoring_recovered_at)->toBeNull()
        ->and($secondTicket->linked('source_alert')->sole()->linkable->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($firstTicket->linked('source_alert')->sole()->linkable->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(data_get($firstTicket->linked('source_alert')->sole()->linkable->context,
            'monitoring_recoveries.'.MonitoringAvailabilityEpisode::fromEvent($first)['key'].'.verification_required'))->toBeTrue()
        ->and($secondTicket->linked('source_alert')->sole()->linkable->context)->not->toHaveKey('monitoring_recoveries')
        ->and(AuditLog::query()->where('action', 'control_room.monitoring.recovery_recorded')->count())->toBe(1)
        ->and($firstTicket->events()->where('type', 'monitoring_recovered')->count())->toBe(1)
        ->and($firstOutbox->fresh()->it_status)->toBe('applied');
});

test('recovery delivered before IT creation is retained as verification evidence without closing the later ticket', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    $outbox = deliverEpisodeSource($failure, false);
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    deliverEpisodeSource($recovery);
    expect(ItTicket::query()->count())->toBe(0);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    $ticket = ItTicket::query()->sole();
    expect($ticket->status)->toBe('open')->and($ticket->status_reason)->toBe('monitoring_recovered')
        ->and($ticket->monitoring_recovered_at->equalTo($recovery->occurred_at))->toBeTrue()
        ->and($ticket->events()->where('type', 'monitoring_recovered')->count())->toBe(1);
});

test('native recovery requires an audit commit and retries without resolving operational work', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    deliverEpisodeSource($failure);
    $alert = ControlRoomAlert::query()->sole();
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    $failAudit = true;
    AuditLog::creating(function (AuditLog $audit) use (&$failAudit): void {
        if ($failAudit && $audit->action === 'control_room.monitoring.recovery_recorded') {
            throw new RuntimeException('Injected recovery audit failure');
        }
    });
    expect(fn () => deliverEpisodeSource($recovery))->toThrow(RuntimeException::class);
    expect($alert->fresh()->context)->not->toHaveKey('monitoring_recoveries');
    expect(ItTicket::query()->sole()->monitoring_recovered_at)->toBeNull();
    $failAudit = false;
    deliverEpisodeSource($recovery);
    deliverEpisodeSource($recovery);
    expect($alert->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(AuditLog::query()->where('action', 'control_room.monitoring.recovery_recorded')->count())->toBe(1)
        ->and(ItTicket::query()->sole()->status)->toBe('open')
        ->and(ItTicket::query()->sole()->monitoring_recovered_at)->not->toBeNull();
});

test('late native recovery preserves a settled alert and only projects evidence to source-authorised roles', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    deliverEpisodeSource($failure);
    $alert = ControlRoomAlert::query()->sole();
    $alert->update(['status' => ControlRoomAlert::STATUS_RESOLVED, 'resolved_at' => now()]);
    $resolvedAt = $alert->resolved_at->toIso8601String();
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    deliverEpisodeSource($recovery);
    $viewer = episodeViewer($site, ['controlRoom.alerts.view', 'securityDevices.devices.view']);
    $presenter = app(MonitoringIncidentEvidencePresenter::class);
    expect($alert->fresh()->status)->toBe(ControlRoomAlert::STATUS_RESOLVED)
        ->and($alert->fresh()->resolved_at->toIso8601String())->toBe($resolvedAt)
        ->and($presenter->recoveryForAlert($alert->fresh(), $viewer))->toBe([
            'observed_at' => $recovery->occurred_at->toIso8601String(), 'verification_required' => true,
        ])
        ->and($presenter->recoveryForAlert($alert->fresh(), episodeViewer($site, ['controlRoom.alerts.view'])))->toBeNull()
        ->and($presenter->recoveryForAlert($alert->fresh(), episodeViewer($site, ['securityDevices.devices.view'])))->toBeNull();
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    expect($presenter->recoveryForAlert($alert->fresh(), episodeViewer($otherSite, ['controlRoom.alerts.view', 'securityDevices.devices.view'])))->toBeNull();
    $workspace = app(AlertWorkspaceService::class)->build($viewer, $alert->id);
    $restrictedWorkspace = app(AlertWorkspaceService::class)->build(episodeViewer($site, ['controlRoom.alerts.view']), $alert->id);
    expect($workspace['monitoring_recovery']['observed_at'])->toBe($recovery->occurred_at->toIso8601String())
        ->and($workspace['alert']['context'])->not->toHaveKey('monitoring_recoveries')
        ->and($restrictedWorkspace['monitoring_recovery'])->toBeNull()
        ->and($restrictedWorkspace['alert']['context'])->not->toHaveKey('monitoring_recoveries');
});

test('a recovery without its own canonical observation cannot settle operational or technical evidence', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    deliverEpisodeSource($failure);
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    $payload = $recovery->payload;
    $payload['observation_id'] = $failure->payload['observation_id'];
    $recovery->update(['payload' => $payload]);
    $outbox = deliverEpisodeSource($recovery);
    expect($outbox->it_status)->toBe('unroutable')
        ->and(ControlRoomAlert::query()->sole()->context)->not->toHaveKey('monitoring_recoveries')
        ->and(ItTicket::query()->sole()->monitoring_recovered_at)->toBeNull()
        ->and(MonitoringAvailabilityEpisode::recoveryFor($failure, (int) $site->id))->toBeNull();
});

test('a recovered episode delivered late does not create a stale Control Room alarm or falsely complete technical work', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    deliverEpisodeSource($recovery);
    $outbox = deliverEpisodeSource($failure);
    expect(ControlRoomAlert::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(1)
        ->and($outbox->status)->toBe('sent')->and($outbox->it_status)->toBe('applied')
        ->and(ItTicket::query()->sole()->status)->toBe('open')
        ->and(ItTicket::query()->sole()->status_reason)->toBe('monitoring_recovered');
});

test('maintenance suppression retains the episode and a confirmed healthy observation emits its recovery', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    deliverEpisodeSource($failure);
    $originalKey = MonitoringAvailabilityEpisode::fromEvent($failure)['key'];
    MonitoringMaintenanceWindow::query()->create([
        'site_id' => $site->id, 'monitor_id' => $monitor->id, 'name' => 'Isolated maintenance',
        'reason' => 'Isolated episode recovery verification',
        'starts_at' => now()->addSeconds(2), 'ends_at' => now()->addHour(),
        'timezone' => 'UTC', 'policy' => 'suppress_notifications_and_ticketing', 'status' => 'active',
    ]);
    $suppressed = episodeObservation($monitor, $site, MonitorState::Failed, 3);
    expect($suppressed->deviceEvent)->toBeNull()->and($monitor->fresh()->effective_state)->toBe(MonitorState::Suppressed);
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 4)->deviceEvent;
    expect($recovery?->event_type)->toBe('online')
        ->and(MonitoringAvailabilityEpisode::fromEvent($recovery)['key'])->toBe($originalKey)
        ->and($monitor->fresh()->availability_episode)->toBeNull();
    deliverEpisodeSource($recovery);
    expect(ItTicket::query()->sole()->status_reason)->toBe('monitoring_recovered');
});

test('resuming a still-failed monitor after maintenance reuses its episode beyond the generic alert deduplication window', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    deliverEpisodeSource($failure);
    MonitoringMaintenanceWindow::query()->create([
        'site_id' => $site->id, 'monitor_id' => $monitor->id, 'name' => 'Isolated long maintenance',
        'reason' => 'Isolated episode continuity verification',
        'starts_at' => now()->addSeconds(2), 'ends_at' => now()->addHours(2),
        'timezone' => 'UTC', 'policy' => 'suppress_notifications_and_ticketing', 'status' => 'active',
    ]);
    episodeObservation($monitor, $site, MonitorState::Failed, 3);
    $this->travelTo(CarbonImmutable::parse('2026-09-12T03:00:02Z'));
    $resumed = episodeObservation($monitor, $site, MonitorState::Failed, 7202)->deviceEvent;
    expect(MonitoringAvailabilityEpisode::fromEvent($resumed)['key'])->toBe(MonitoringAvailabilityEpisode::fromEvent($failure)['key']);
    deliverEpisodeSource($resumed);
    expect(ControlRoomAlert::query()->count())->toBe(1)->and(ItTicket::query()->count())->toBe(1)
        ->and(ItTicket::query()->sole()->events()->where('type', 'monitoring_evidence_added')->count())->toBe(1);
});

test('a new confirmed episode after closure creates fresh work and preserves the old settled record', function () {
    [$monitor, $site] = episodeMonitor();
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    $old = ItTicket::query()->sole();
    $old->update(['status' => 'closed', 'closed_at' => now()]);
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent);
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 3)->deviceEvent);
    expect(ItTicket::query()->count())->toBe(2)->and($old->fresh()->status)->toBe('closed')
        ->and(ItTicket::query()->where('id', '!=', $old->id)->sole()->status)->toBe('open');
});

test('confirmed threshold state defines an outage even when the raw observation reports healthy', function () {
    [$monitor, $site] = episodeMonitor(['rising_threshold' => 80, 'falling_threshold' => 60]);
    $result = episodeObservation($monitor, $site, MonitorState::Healthy, 1, value: 90);
    expect($result->observation->state)->toBe(MonitorState::Healthy)
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($result->deviceEvent?->event_type)->toBe('offline')
        ->and(MonitoringAvailabilityEpisode::fromEvent($result->deviceEvent))->not->toBeNull();
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2, value: 40);
    expect($recovery->deviceEvent?->event_type)->toBe('online');
});

test('missing episode state produces unmatched recovery instead of closing an unproven outage', function () {
    [$monitor, $site] = episodeMonitor();
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    $monitor->fresh()->forceFill(['availability_episode' => null])->save();
    $recovery = episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent;
    $outbox = deliverEpisodeSource($recovery);
    expect($outbox->it_status)->toBe('ignored')->and($outbox->it_outcome_code)->toBe('recovery_unmatched')
        ->and(ControlRoomAlert::query()->sole()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(ItTicket::query()->sole()->monitoring_recovered_at)->toBeNull();
});

test('observation replay and late older evidence cannot rewind or duplicate an episode', function () {
    [$monitor, $site] = episodeMonitor();
    $first = episodeObservation($monitor, $site, MonitorState::Failed, 1, 'episode-original');
    $replay = episodeObservation($monitor, $site, MonitorState::Failed, 1, 'episode-original');
    expect($replay->duplicate)->toBeTrue()->and(DeviceEvent::query()->count())->toBe(1);
    episodeObservation($monitor, $site, MonitorState::Healthy, 3);
    $late = episodeObservation($monitor, $site, MonitorState::Failed, 2, 'late-older-failure');
    expect($late->deviceEvent)->toBeNull()->and($monitor->fresh()->availability_episode)->toBeNull()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and(DeviceEvent::query()->count())->toBe(2);
});

test('episode and monitor state roll back with source-event publication failure and retry once', function () {
    [$monitor, $site] = episodeMonitor();
    $fail = true;
    DeviceEvent::creating(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('injected native source publication failure');
        }
    });
    expect(fn () => episodeObservation($monitor, $site, MonitorState::Failed, 1, 'retry-source'))->toThrow(RuntimeException::class);
    expect($monitor->fresh()->availability_episode)->toBeNull()->and($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and($monitor->observations()->count())->toBe(0)->and(DeviceEventSignalOutbox::query()->count())->toBe(0);
    $fail = false;
    $retry = episodeObservation($monitor, $site, MonitorState::Failed, 1, 'retry-source');
    expect($retry->deviceEvent)->not->toBeNull()->and($monitor->observations()->count())->toBe(1)
        ->and(DeviceEventSignalOutbox::query()->count())->toBe(1);
});

test('a forged episode tuple fails closed before producing an alert or ticket', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    $payload = $failure->payload;
    $payload['availability_episode']['site_id'] = $site->id + 1000;
    $failure->update(['payload' => $payload]);
    $outbox = deliverEpisodeSource($failure);
    expect($outbox->status)->toBe('unroutable')->and(ControlRoomAlert::query()->count())->toBe(0)
        ->and(ItTicket::query()->count())->toBe(0)
        ->and($monitor->fresh()->toArray())->not->toHaveKey('availability_episode');
});

test('a legacy broad recovery cannot settle a new native episode', function () {
    [$monitor, $site] = episodeMonitor();
    $failure = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    deliverEpisodeSource($failure);
    $legacy = DeviceEvent::query()->create([
        'device_id' => $monitor->device_id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => now()->addSeconds(2),
        'payload' => ['legacy_monitoring_recovery' => true,
            'monitor_correlation_key' => data_get($failure->payload, 'monitor_correlation_key')],
    ]);
    deliverEpisodeSource($legacy);
    expect(ControlRoomAlert::query()->sole()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(ItTicket::query()->sole()->monitoring_recovered_at)->toBeNull();
});

function episodeViewer(Site $site, array $permissions = ['it.view', 'it.manage', 'securityDevices.devices.view']): User
{
    $viewer = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create(['name' => 'episode-'.str()->uuid(), 'label' => 'Isolated episode role', 'level' => 60, 'type' => 'custom']);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $viewer->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        'created_by' => $viewer->id, 'updated_by' => $viewer->id,
    ]);

    return $viewer;
}

test('configured nonurgent faults create one direct ticket and sealed source evidence despite a high transport hint', function (string $severity, string $priority) {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => $severity]);
    [$monitor, $site] = episodeMonitor();
    $event = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    expect($event->severity)->toBe('high');
    $outbox = deliverEpisodeSource($event);
    deliverEpisodeSource($event);
    $ticket = ItTicket::query()->sole();
    $snapshot = MonitoringIncidentEvidenceSnapshot::query()->sole();
    expect(ControlRoomAlert::query()->count())->toBe(0)->and($ticket->priority)->toBe($priority)
        ->and($ticket->priority_decision['mode'])->toBe('automatic')
        ->and($ticket->links()->where('relationship', 'source_alert')->count())->toBe(0)
        ->and($ticket->links()->where('relationship', 'affected_device')->count())->toBe(1)
        ->and($snapshot->control_room_alert_id)->toBeNull()->and($snapshot->snapshot['alert'])->toBeNull()
        ->and($snapshot->hasValidChecksum())->toBeTrue()->and($snapshot->evidence_version)->toBe(2)
        ->and($outbox->it_status)->toBe('applied')->and($outbox->it_scope['work_routing']['destination'])->toBe('it');
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Healthy, 2)->deviceEvent);
    expect($ticket->fresh()->status)->toBe('open')->and($ticket->fresh()->status_reason)->toBe('monitoring_recovered')
        ->and($snapshot->fresh()->checksum)->toBe($snapshot->checksum);
})->with([['medium', 'normal'], ['low', 'low'], ['info', 'low']]);

test('direct technical work uses the current eligible queue owner and cover without inventing people', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    $owner = episodeViewer($site);
    $cover = episodeViewer($site);
    $team = ItTeam::factory()->create(['manager_user_id' => $owner->id]);
    $team->members()->attach($cover->id, ['role' => 'member']);
    $queue = ItQueue::factory()->create(['team_id' => $team->id, 'filter_rules' => [
        'is_default' => true, 'site_ids' => [$site->id], 'cover_user_id' => $cover->id,
        'default_assignee_user_id' => $owner->id,
    ]]);
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    $ticket = ItTicket::query()->sole();
    expect($ticket->queue_id)->toBe($queue->id)->and($ticket->team_id)->toBe($team->id)
        ->and($ticket->owner_user_id)->toBe($owner->id)->and($ticket->assigned_to_user_id)->toBe($owner->id)
        ->and($ticket->routing_decision['cover_user_id'])->toBe($cover->id)->and($ticket->routing_decision['gaps'])->toBe([]);
});

test('direct evidence needs current ticket and device access without requiring unrelated Control Room access', function () {
    config()->set('inertia.ssr.enabled', false);
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    $ticket = ItTicket::query()->sole();
    $viewer = episodeViewer($site);
    $presenter = app(MonitoringIncidentEvidencePresenter::class);
    expect($viewer->canDo('controlRoom.alerts.view'))->toBeFalse()
        ->and($presenter->forTicket($ticket, $viewer))->toHaveCount(1)
        ->and($presenter->forTicket($ticket, episodeViewer($site, ['it.view', 'it.manage'])))->toBe([])
        ->and($presenter->forTicket($ticket, episodeViewer(Site::factory()->create())))->toBe([]);
    $this->actingAs($viewer)->get('/it/tickets/'.$ticket->id)->assertOk()->assertInertia(fn ($page) => $page
        ->has('linked_context.incident_evidence', 1)->where('linked_context.incident_evidence.0.alert', null));
    $site->update(['is_active' => false]);
    expect($presenter->forTicket($ticket->fresh(), $viewer))->toBe([]);
});

test('a direct routing decision survives a policy edit while mutated recorded decisions are rejected', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    $event = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    $outbox = deliverEpisodeSource($event, false);
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'critical']);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    expect(ItTicket::query()->sole()->priority)->toBe('normal')->and(ControlRoomAlert::query()->count())->toBe(0);
    [$other, $otherSite] = episodeMonitor();
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    $otherOutbox = deliverEpisodeSource(episodeObservation($other, $otherSite, MonitorState::Failed, 1)->deviceEvent, false);
    $signal = Signal::query()->findOrFail($otherOutbox->it_signal_id);
    $data = $signal->normalized_data;
    $data['it_work_routing']['severity'] = 'critical';
    $signal->update(['normalized_data' => $data]);
    app()->call([new DispatchDeviceMonitoringTicket($otherOutbox->id), 'handle']);
    expect($otherOutbox->fresh()->it_status)->toBe('unroutable')->and(ItTicket::query()->count())->toBe(1);
});

test('missing canonical observation evidence rejects direct routing before source acknowledgement', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    $event = episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent;
    $payload = $event->payload;
    $payload['observation_id'] = 999999999;
    $event->update(['payload' => $payload]);
    $outbox = deliverEpisodeSource($event);
    expect($outbox->status)->toBe('unroutable')->and(ItTicket::query()->count())->toBe(0)->and(ControlRoomAlert::query()->count())->toBe(0);
});

test('direct creation failure remains independently retryable without an operational alert', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    $outbox = deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent, false);
    $fail = true;
    MonitoringIncidentEvidenceSnapshot::creating(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('isolated direct evidence failure');
        }
    });
    expect(fn () => app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']))->toThrow(RuntimeException::class);
    expect(ItTicket::query()->count())->toBe(0)->and(ControlRoomAlert::query()->count())->toBe(0)
        ->and($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_status)->toBe('failed');
    $fail = false;
    app(ItMonitoringDeliveryService::class)->retry($outbox->id);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    expect(ItTicket::query()->count())->toBe(1)->and(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(1)
        ->and($outbox->fresh()->it_status)->toBe('applied');
});

test('operational or unassessed faults keep Control Room coordination', function (?string $severity) {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => $severity]);
    [$monitor, $site] = episodeMonitor();
    $outbox = deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    expect(ControlRoomAlert::query()->count())->toBe(1)->and(ItTicket::query()->count())->toBe(1)
        ->and($outbox->it_scope['work_routing']['destination'])->toBe('control_room')
        ->and(ItTicket::query()->sole()->links()->where('relationship', 'source_alert')->count())->toBe(1);
})->with([['high'], ['critical'], [null]]);

test('resumed evidence for a settled episode does not create or reopen technical work', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    $ticket = ItTicket::query()->sole();
    $ticket->update(['status' => 'closed', 'status_reason' => null, 'closed_at' => now()]);
    MonitoringMaintenanceWindow::query()->create([
        'site_id' => $site->id, 'monitor_id' => $monitor->id, 'name' => 'Isolated settled-episode maintenance',
        'reason' => 'Verify retained settled work', 'starts_at' => now()->addSeconds(2), 'ends_at' => now()->addSeconds(5),
        'timezone' => 'UTC', 'policy' => 'suppress_notifications_and_ticketing', 'status' => 'active',
    ]);
    episodeObservation($monitor, $site, MonitorState::Failed, 3);
    deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 6)->deviceEvent);
    expect(ItTicket::query()->count())->toBe(1)->and($ticket->fresh()->status)->toBe('closed')
        ->and($ticket->fresh()->status_reason)->toBeNull()
        ->and($ticket->events()->where('type', 'monitoring_evidence_added')->count())->toBe(1);
});

test('direct queued work denies a device moved to another Site after source delivery', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    [$monitor, $site] = episodeMonitor();
    $outbox = deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent, false);
    $other = Site::factory()->create();
    DeviceAssignment::query()->where('device_id', $monitor->device_id)->update(['assignable_id' => $other->id]);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    expect($outbox->fresh()->it_status)->toBe('unroutable')->and($outbox->fresh()->it_outcome_code)->toBe('source_scope_changed')
        ->and(ItTicket::query()->count())->toBe(0);
});

test('retained type-FK rules remain specific and explicit wildcard rules preserve their configured precedence', function () {
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'medium']);
    // Reproduce retained rows created by the previous seeder without rewriting their policy.
    SignalRule::query()->update(['signal_type_code' => null]);
    [$monitor, $site] = episodeMonitor();
    $outbox = deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    expect(ControlRoomAlert::query()->count())->toBe(0)->and($outbox->it_scope['work_routing']['severity'])->toBe('medium');
    SignalRule::query()->create(['name' => 'Isolated explicit operational wildcard', 'signal_type_code' => null,
        'signal_type_id' => null, 'signal_source_id' => null, 'priority' => 1, 'is_active' => true, 'output_severity' => 'critical']);
    [$other, $otherSite] = episodeMonitor();
    $urgent = deliverEpisodeSource(episodeObservation($other, $otherSite, MonitorState::Failed, 1)->deviceEvent);
    expect(ControlRoomAlert::query()->count())->toBe(1)->and($urgent->it_scope['work_routing']['destination'])->toBe('control_room');
});

test('conflicting rule type identifiers cannot authorize bypassing operational coordination', function () {
    $wrongType = SignalType::query()->where('code', 'device_panic_button')->value('id');
    // Use an actual other registered type; never a fabricated FK.
    $wrongType ??= SignalType::query()->where('code', 'device_alarm_trigger')->sole()->id;
    SignalRule::query()->where('signal_type_code', 'device_offline')->update(['signal_type_id' => $wrongType, 'output_severity' => 'low']);
    [$monitor, $site] = episodeMonitor();
    $outbox = deliverEpisodeSource(episodeObservation($monitor, $site, MonitorState::Failed, 1)->deviceEvent);
    expect(ControlRoomAlert::query()->count())->toBe(1)->and($outbox->it_scope['work_routing']['destination'])->toBe('control_room')
        ->and($outbox->it_scope['work_routing']['rule_id'])->toBeNull();
});
