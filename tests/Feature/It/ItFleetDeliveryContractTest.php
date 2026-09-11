<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\It\Services\ItFleetAvailability;
use App\Domain\It\Services\ItFleetDeliveryService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Events\FleetSignalEmitted;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchFleetMonitoringTicket;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->freezeTime();
    config(['services.telemetry.ingest_token' => 'test-token', 'fleet.signals.offline_after_minutes' => 15]);
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();
    Event::fake([FleetSignalEmitted::class]);
    SignalSource::query()->firstOrCreate(['slug' => 'queclink_fleet'], [
        'name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => 'active',
    ]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->asset = Asset::factory()->vehicle()->create([
        'site_id' => $this->site->id, 'home_site_id' => null, 'client_id' => null,
    ]);
    $this->device = Device::factory()->tracking()->create([
        'provider' => 'queclink', 'imei' => 'IT-FLEET-DELIVERY', 'device_uid' => 'IT-FLEET-DELIVERY',
    ]);
    $this->pairing = DeviceAssetLink::query()->create([
        'device_id' => $this->device->id, 'asset_id' => $this->asset->id,
        'link_type' => LinkType::InstalledIn, 'linked_at' => now(),
    ]);
    $this->heartbeat = function (): void {
        $this->withHeader('X-Telemetry-Token', 'test-token')->postJson('/telemetry/ingest/queclink', [
            'imei' => $this->device->imei, 'gps_time' => now()->toISOString(),
            'event_type' => 'heartbeat', 'private_context' => 'private-provider-context',
        ])->assertOk();
    };
    ($this->heartbeat)();
    $this->travel(16)->minutes();
    (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
    $this->offline = FleetSignal::query()->where('signal_type', 'device.offline')->sole();
});

/** Exercise the source-acknowledgement transaction seam, before wiring the production consumer. */
function prepareFleetItContract(FleetSignal $event): FleetSignalOutbox
{
    return DB::transaction(function () use ($event): FleetSignalOutbox {
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $event->id)->lockForUpdate()->sole();
        $processor = app(SignalProcessingService::class);
        $signal = $processor->ingestFromFleetSignal($event);
        $processor->processFleetAvailability($signal);
        app(ItFleetDeliveryService::class)->prepare($event, $signal);
        $outbox->update(['status' => 'sent']);

        return $outbox->fresh();
    });
}

function applyFleetContractTicket(ItFleetAvailability $source): array
{
    $ticket = ItTicket::createWithReference([
        'site_id' => $source->siteId, 'is_organisation_wide' => false,
        'title' => 'Synthetic delivery transaction', 'description' => 'Isolated IT delivery contract fixture.',
        'source' => 'system', 'work_type' => 'incident', 'category' => 'hardware',
        'status' => 'open', 'priority' => 'normal', 'requires_approval' => false,
    ]);

    return ['outcome' => 'ticket_created', 'ticket_ids' => [(int) $ticket->id]];
}

test('Fleet IT delivery commits once and retains the independent acknowledged operational alert', function () {
    $outbox = prepareFleetItContract($this->offline);
    $alert = ControlRoomAlert::query()->sole();
    expect($outbox->it_status)->toBe('pending')->and($outbox->status)->toBe('sent');
    $deliveries = app(ItFleetDeliveryService::class);
    $deliveries->deliver($outbox->id, applyFleetContractTicket(...));
    $ticket = ItTicket::query()->sole();
    $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
    $deliveries->deliver($outbox->id, fn () => throw new RuntimeException('Replay must not invoke the consumer'));
    expect(ItTicket::query()->count())->toBe(1)->and($ticket->fresh()->status)->toBe('resolved')
        ->and($outbox->fresh()->it_status)->toBe('applied')->and($outbox->fresh()->it_attempts)->toBe(1)
        ->and($outbox->fresh()->it_ticket_ids)->toBe([$ticket->id])
        ->and(ControlRoomAlert::query()->sole()->id)->toBe($alert->id)
        ->and(ControlRoomAlert::query()->sole()->status)->toBe($alert->status)
        ->and(AuditLog::query()->where('action', 'it.fleet.delivery_completed')->count())->toBe(1)
        ->and(json_encode($outbox->fresh()->toArray()))->not->toContain('private-provider-context');
});

test('Fleet consumer failure rolls back writes and exhausts only IT attempts', function () {
    $outbox = prepareFleetItContract($this->offline);
    $deliveries = app(ItFleetDeliveryService::class);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => $deliveries->deliver($outbox->id, function (ItFleetAvailability $source): array {
            applyFleetContractTicket($source);
            throw new RuntimeException('private-provider-context SQL failure');
        }))->toThrow(RuntimeException::class);
        expect($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_attempts)->toBe($attempt)
            ->and(ItTicket::query()->count())->toBe(0);
    }
    expect($outbox->fresh()->it_status)->toBe('dead_letter')
        ->and($outbox->fresh()->it_outcome_code)->toBe('processing_failed');
    $deliveries->deliver($outbox->id, fn () => throw new RuntimeException('No fourth automatic attempt'));
    expect($outbox->fresh()->it_attempts)->toBe(3)
        ->and(AuditLog::query()->where('action', 'it.fleet.delivery_completed')->count())->toBe(0);
});

test('Fleet audit failure rolls back the consumer and permits a subsequent successful attempt', function () {
    $outbox = prepareFleetItContract($this->offline);
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'it.fleet.delivery_completed') {
            throw new RuntimeException('Injected audit failure');
        }
    });
    $deliveries = app(ItFleetDeliveryService::class);
    expect(fn () => $deliveries->deliver($outbox->id, applyFleetContractTicket(...)))->toThrow(RuntimeException::class);
    expect(ItTicket::query()->count())->toBe(0)->and($outbox->fresh()->it_status)->toBe('failed');
    $fail = false;
    $deliveries->deliver($outbox->id, applyFleetContractTicket(...));
    expect(ItTicket::query()->count())->toBe(1)->and($outbox->fresh()->it_attempts)->toBe(2)
        ->and($outbox->fresh()->it_status)->toBe('applied');
});

test('Fleet delayed delivery rejects changed canonical source boundaries', function (string $change) {
    $outbox = prepareFleetItContract($this->offline);
    $signal = Signal::query()->findOrFail($outbox->it_signal_id);
    match ($change) {
        'site' => $this->asset->update(['site_id' => Site::factory()->create()->id]),
        'inactive_site' => $this->site->update(['is_active' => false]),
        'archived_site' => $this->site->update(['archived_at' => now()]),
        'pairing' => $this->pairing->update(['unlinked_at' => now()]),
        'provider' => $this->device->update(['provider' => 'other']),
        'source' => $signal->signalSource->update(['status' => 'inactive']),
        'episode' => $this->offline->update(['idempotency_key' => hash('sha256', 'changed')]),
        'occurrence' => $this->offline->update(['occurred_at' => now()->addMinute()]),
        'signal' => $signal->update(['normalized_data' => ['fleet_signal_id' => $this->offline->id + 1000]]),
        'routing' => $signal->update(['normalized_data' => [...$signal->normalized_data, 'it_work_routing' => ['destination' => 'it']]]),
        'suppressed' => $signal->update(['status' => 'suppressed']),
    };
    app(ItFleetDeliveryService::class)->deliver($outbox->id, fn () => throw new RuntimeException('Untrusted source reached consumer'));
    expect($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_status)->toBe('unroutable')
        ->and($outbox->fresh()->it_attempts)->toBe(1)->and(ItTicket::query()->count())->toBe(0);
})->with(['site', 'inactive_site', 'archived_site', 'pairing', 'provider', 'source', 'episode', 'occurrence', 'signal', 'routing', 'suppressed']);

test('Fleet recovered episode passes its true paired offline identity even when delivered first', function () {
    ($this->heartbeat)();
    $recovery = FleetSignal::query()->where('signal_type', 'device.online')->sole();
    $outbox = prepareFleetItContract($recovery);
    app(ItFleetDeliveryService::class)->deliver($outbox->id, function (ItFleetAvailability $source): array {
        expect($source->event->signal_type)->toBe('device.online')
            ->and($source->offline->id)->toBe($this->offline->id)
            ->and($source->siteId)->toBe($this->site->id);

        return ['outcome' => 'recovery_unmatched', 'ticket_ids' => []];
    });
    expect($outbox->fresh()->it_status)->toBe('ignored')->and(ControlRoomAlert::query()->count())->toBe(0);
    $offlineOutbox = prepareFleetItContract($this->offline);
    app(ItFleetDeliveryService::class)->deliver($offlineOutbox->id, applyFleetContractTicket(...));
    expect($offlineOutbox->fresh()->it_status)->toBe('applied')->and(ControlRoomAlert::query()->count())->toBe(0);
});

test('Fleet pre-contract acknowledgements stay unknown and suppressed sources have no IT intent', function () {
    $processor = app(SignalProcessingService::class);
    $signal = $processor->ingestFromFleetSignal($this->offline);
    $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $this->offline->id)->sole();
    $outbox->update(['status' => 'sent']);
    app(ItFleetDeliveryService::class)->prepare($this->offline, $signal);
    expect($outbox->fresh()->it_status)->toBeNull();
    $outbox->update(['status' => 'pending']);
    $signal->update(['status' => 'suppressed']);
    app(ItFleetDeliveryService::class)->prepare($this->offline, $signal);
    expect($outbox->fresh()->it_status)->toBe('ignored')
        ->and($outbox->fresh()->it_outcome_code)->toBe('source_suppressed');
});

test('Fleet rollback refuses to discard recorded IT outcomes', function () {
    prepareFleetItContract($this->offline);
    $migration = require database_path('migrations/2026_09_12_000028_add_fleet_it_delivery.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class);
});

test('Fleet recovery occurrence must agree with the persisted heartbeat before intent preparation', function () {
    ($this->heartbeat)();
    $recovery = FleetSignal::query()->where('signal_type', 'device.online')->sole();
    $recovery->update(['occurred_at' => now()->addMinute()]);
    expect(app(FleetSignalService::class)->matchedOfflineForRecovery($recovery))->toBeNull();
    $outbox = prepareFleetItContract($recovery);
    app(ItFleetDeliveryService::class)->deliver($outbox->id, fn () => throw new RuntimeException('Forged recovery reached consumer'));
    expect($outbox->fresh()->it_status)->toBe('unroutable')->and(ItTicket::query()->count())->toBe(0);
});

test('Fleet IT cannot run before acknowledgement or overwrite an already prepared source identity', function () {
    $outbox = prepareFleetItContract($this->offline);
    $identity = $outbox->it_scope;
    $outbox->update(['status' => 'pending']);
    $deliveries = app(ItFleetDeliveryService::class);
    $deliveries->deliver($outbox->id, fn () => throw new RuntimeException('Source acknowledgement required'));
    expect($outbox->fresh()->it_attempts)->toBe(0)->and($outbox->fresh()->it_status)->toBe('pending');
    $this->offline->update(['idempotency_key' => hash('sha256', 'changed-before-reprepare')]);
    $deliveries->prepare($this->offline, Signal::query()->findOrFail($outbox->it_signal_id));
    expect($outbox->fresh()->it_scope)->toBe($identity);
});

test('Fleet driver-safety events remain outside automatic IT intake', function () {
    $this->offline->update(['signal_type' => 'sos']);
    $signal = app(SignalProcessingService::class)->ingestFromFleetSignal($this->offline);
    app(ItFleetDeliveryService::class)->prepare($this->offline, $signal);
    $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $this->offline->id)->sole();
    expect($outbox->it_status)->toBe('ignored')->and($outbox->it_outcome_code)->toBe('unsupported_event')
        ->and($outbox->it_attempts)->toBe(0)->and(ItTicket::query()->count())->toBe(0);
});

function deliverFleetTechnicalWork(FleetSignal $event): FleetSignalOutbox
{
    $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $event->id)->sole();
    app()->call([new DispatchFleetSignalOutbox($outbox->id), 'handle']);
    app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']);

    return $outbox->fresh();
}

function fleetWorkRule(string $severity): SignalRule
{
    return SignalRule::query()->create([
        'name' => 'Isolated Fleet technical rule', 'signal_type_code' => 'fleet_device_offline',
        'priority' => 1000, 'is_active' => true, 'output_severity' => $severity,
        'output_tier' => 2, 'output_escalation_level' => 1, 'deduplicate' => true,
    ]);
}

function fleetWorkViewer(Site $site, array $permissions): User
{
    $viewer = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create(['name' => 'fleet-work-'.str()->uuid(), 'label' => 'Fleet work verification', 'level' => 60, 'type' => 'custom']);
    foreach ($permissions as $permissionKey) {
        $permission = Permission::query()->firstOrCreate(['key' => $permissionKey], ['description' => $permissionKey, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $viewer->roles()->attach($role);
    HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'created_by' => $viewer->id, 'updated_by' => $viewer->id,
        'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

    return $viewer;
}

test('real Fleet source and IT jobs create one canonical direct ticket and a sealed Fleet snapshot', function () {
    fleetWorkRule('medium');
    $outbox = deliverFleetTechnicalWork($this->offline);
    expect($outbox->status)->toBe('sent')->and($outbox->it_status)->toBe('applied')
        ->and($outbox->it_outcome_code)->toBe('ticket_created')->and(ControlRoomAlert::query()->count())->toBe(0);
    $ticket = ItTicket::query()->sole();
    $evidence = MonitoringIncidentEvidenceSnapshot::query()->sole();
    expect($ticket->site_id)->toBe($this->site->id)->and($ticket->status)->toBe('open')
        ->and($ticket->links()->where('relationship', 'affected_asset')->count())->toBe(1)
        ->and($ticket->links()->where('relationship', 'affected_device')->count())->toBe(1)
        ->and($evidence->evidence_version)->toBe(3)->and($evidence->device_event_id)->toBeNull()
        ->and((int) $evidence->fleet_signal_id)->toBe($this->offline->id)->and($evidence->hasValidChecksum())->toBeTrue()
        ->and(json_encode($evidence->snapshot))->not->toContain('private-provider-context', 'latitude', 'driver_session');
    $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
    deliverFleetTechnicalWork($this->offline);
    expect(ItTicket::query()->count())->toBe(1)->and($ticket->fresh()->status)->toBe('resolved')
        ->and($outbox->fresh()->it_attempts)->toBe(1);
});

test('urgent Fleet work keeps its operational alert and exact recovery never closes either record', function () {
    fleetWorkRule('high');
    $outbox = deliverFleetTechnicalWork($this->offline);
    expect($outbox->it_status)->toBe('applied');
    $ticket = ItTicket::query()->sole();
    $alert = ControlRoomAlert::query()->sole();
    expect($ticket->links()->where('relationship', 'source_alert')->where('linkable_id', $alert->id)->exists())->toBeTrue();
    ($this->heartbeat)();
    $recovery = FleetSignal::query()->where('signal_type', 'device.online')->sole();
    $result = deliverFleetTechnicalWork($recovery);
    expect($result->it_status)->toBe('applied')->and($result->it_outcome_code)->toBe('recovery_recorded')
        ->and($ticket->fresh()->monitoring_recovered_at)->not->toBeNull()
        ->and($ticket->fresh()->status)->toBe('open')->and($alert->fresh()->status)->toBe($alert->status)
        ->and($ticket->events()->where('type', 'monitoring_recovered')->count())->toBe(1);
    deliverFleetTechnicalWork($recovery);
    expect($ticket->events()->where('type', 'monitoring_recovered')->count())->toBe(1);
});

test('reordered Fleet recovery creates open verification work without a stale alert and a later episode gets new work', function () {
    ($this->heartbeat)();
    $recovery = FleetSignal::query()->where('signal_type', 'device.online')->sole();
    expect(deliverFleetTechnicalWork($recovery)->it_outcome_code)->toBe('recovery_unmatched');
    expect(deliverFleetTechnicalWork($this->offline)->it_status)->toBe('applied');
    $first = ItTicket::query()->sole();
    expect($first->status_reason)->toBe('monitoring_recovered')->and($first->status)->toBe('open')
        ->and(ControlRoomAlert::query()->count())->toBe(0);
    $first->update(['status' => 'resolved', 'resolved_at' => now()]);
    fleetWorkRule('medium');
    $this->travel(16)->minutes();
    (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
    $later = FleetSignal::query()->where('signal_type', 'device.offline')->latest('id')->firstOrFail();
    expect($later->id)->not->toBe($this->offline->id);
    expect(deliverFleetTechnicalWork($later)->it_status)->toBe('applied');
    expect(ItTicket::query()->count())->toBe(2)->and($first->fresh()->status)->toBe('resolved')
        ->and(ItTicket::query()->latest('id')->first()->monitoring_recovered_at)->toBeNull();
});

test('Fleet IT retry recovers a failed real ticket write without resending the source alert', function () {
    fleetWorkRule('high');
    $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $this->offline->id)->sole();
    app()->call([new DispatchFleetSignalOutbox($outbox->id), 'handle']);
    $fail = true;
    ItTicket::created(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('Injected Fleet ticket write failure');
        }
    });
    expect(fn () => app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']))->toThrow(RuntimeException::class);
    expect($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_status)->toBe('failed')
        ->and(ItTicket::query()->count())->toBe(0)->and(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(0);
    $recovery = app(SafetySignalDeliveryRecoveryService::class);
    expect($recovery->recover(100, true)['fleet_it']['failures'])->toBe(1);
    $fail = false;
    $recovery->retry('fleet_it', $outbox->id);
    Queue::assertPushed(DispatchFleetMonitoringTicket::class, fn ($job) => $job->outboxId === $outbox->id);
    app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']);
    expect($outbox->fresh()->it_status)->toBe('applied')->and($outbox->fresh()->it_attempts)->toBe(2)
        ->and(ItTicket::query()->count())->toBe(1)->and(ControlRoomAlert::query()->count())->toBe(1);
});

test('Fleet source evidence requires current asset and device grants while ticket access remains usable', function () {
    fleetWorkRule('medium');
    expect(deliverFleetTechnicalWork($this->offline)->it_status)->toBe('applied');
    $ticket = ItTicket::query()->sole();
    $viewer = fleetWorkViewer($this->site, ['it.view', 'it.manage', 'assets.viewAny', 'securityDevices.devices.view']);
    $presenter = app(ItTicketContextPresenter::class);
    $context = $presenter->present($ticket, $viewer);
    expect($context['incident_evidence'])->toHaveCount(1)->and($context['assets'][0]['id'])->toBe($this->asset->id)
        ->and($context['assets'][0]['href'])->toContain('/fleet-assets/assets/'.$this->asset->id);
    $restricted = fleetWorkViewer($this->site, ['it.view', 'it.manage']);
    $hidden = $presenter->present($ticket->fresh(), $restricted);
    expect($hidden['incident_evidence'])->toBe([])->and($hidden['assets'][0]['id'])->toBeNull()
        ->and($hidden['assets'][0]['href'])->toBeNull();
    $other = fleetWorkViewer(Site::factory()->create(), ['it.view', 'assets.viewAny', 'securityDevices.devices.view']);
    expect($presenter->present($ticket->fresh(), $other)['incident_evidence'])->toBe([]);
});

test('Fleet maintenance suppresses both direct work and operational alert creation', function () {
    fleetWorkRule('medium');
    MaintenanceWindow::query()->create([
        'name' => 'Isolated Fleet maintenance', 'site_id' => $this->site->id, 'asset_id' => $this->asset->id,
        'status' => 'active', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
        'created_by_user_id' => User::factory()->create()->id,
    ]);
    $outbox = deliverFleetTechnicalWork($this->offline);
    expect($outbox->status)->toBe('sent')->and($outbox->it_status)->toBe('ignored')
        ->and($outbox->it_outcome_code)->toBe('source_suppressed')
        ->and(ItTicket::query()->count())->toBe(0)->and(ControlRoomAlert::query()->count())->toBe(0);
});

test('Fleet queued IT work preserves the already recorded rule assessment', function () {
    $rule = fleetWorkRule('medium');
    $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $this->offline->id)->sole();
    app()->call([new DispatchFleetSignalOutbox($outbox->id), 'handle']);
    $rule->update(['output_severity' => 'high']);
    app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']);
    expect($outbox->fresh()->it_status)->toBe('applied')->and(ItTicket::query()->sole()->urgency)->toBe('normal')
        ->and(ControlRoomAlert::query()->count())->toBe(0);
});

test('Fleet operational recovery audit failure rolls back recovery and can be retried', function () {
    fleetWorkRule('high');
    deliverFleetTechnicalWork($this->offline);
    $ticket = ItTicket::query()->sole();
    $alert = ControlRoomAlert::query()->sole();
    ($this->heartbeat)();
    $recovery = FleetSignal::query()->where('signal_type', 'device.online')->sole();
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'fleet.availability.recovery_recorded') {
            throw new RuntimeException('Injected Fleet recovery audit failure');
        }
    });
    expect(fn () => deliverFleetTechnicalWork($recovery))->toThrow(RuntimeException::class);
    expect($ticket->fresh()->monitoring_recovered_at)->toBeNull()
        ->and($alert->fresh()->context)->toBe($alert->context);
    $fail = false;
    expect(deliverFleetTechnicalWork($recovery)->it_status)->toBe('applied');
    expect($ticket->fresh()->monitoring_recovered_at)->not->toBeNull()
        ->and($ticket->fresh()->status)->toBe('open');
});
