<?php

/** Opt-in, fingerprinted fixture for an owned fresh browser schema only. */

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Jobs\DispatchFleetMonitoringTicket;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Fleet\FleetSignalService;
use App\Services\Fleet\FleetTelemetryIngestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function w14BrowserCreateMonitoringFixtures(array $context, array $fixtures): array
{
    w06BrowserRequire(PHP_SAPI === 'cli' && ($context['monitoring_fixtures'] ?? false)
        && DB::scalar('SELECT DATABASE()') === W06_BROWSER_DATABASE_PREFIX.$context['token']
        && config('mail.default') === 'array' && config('queue.default') === 'sync'
        && Device::query()->count() === 0 && Monitor::query()->count() === 0
        && DeviceEventSignalOutbox::query()->count() === 0,
        'Monitoring fixtures require a reviewed fresh owned schema and local sinks.');
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();

    return DB::transaction(function () use ($context, $fixtures): array {
        app(SecurityDevicesPermissionsSeeder::class)->run();
        app(SecurityDevicesSignalSeeder::class)->run();
        foreach (['tech' => ['securityDevices.viewAny', 'securityDevices.devices.view', 'controlRoom.alerts.view'],
            'cover' => ['securityDevices.viewAny', 'securityDevices.devices.view'],
            'audit' => ['controlRoom.alerts.view']] as $key => $keys) {
            $role = Role::query()->where('name', 'w06-browser-'.$key)->sole();
            $permissions = Permission::query()->whereIn('key', $keys)->pluck('id');
            w06BrowserRequire($permissions->count() === count($keys), 'Canonical source permissions are missing.');
            $role->permissions()->syncWithoutDetaching($permissions);
        }
        $assetPermission = Permission::query()->firstOrCreate(['key' => 'assets.viewAny'], [
            'description' => 'View canonical assets', 'group' => 'assets', 'module' => 'Operations',
        ]);
        foreach (['tech', 'cover'] as $key) {
            Role::query()->where('name', 'w06-browser-'.$key)->sole()->permissions()->syncWithoutDetaching([$assetPermission->id]);
        }
        $tech = User::query()->findOrFail($fixtures['actors']['tech']['id']);
        $cover = User::query()->findOrFail($fixtures['actors']['cover']['id']);
        w06BrowserRequire($tech->canDo('controlRoom.alerts.view') && ! $cover->canDo('controlRoom.alerts.view')
            && $cover->canDo('securityDevices.devices.view'), 'Synthetic source access differs from the intended boundary.');
        $team = ItTeam::factory()->create(['name' => 'W14 '.$context['token'].' synthetic service desk', 'manager_user_id' => $tech->id]);
        $team->members()->attach($cover->id, ['role' => 'member']);
        $queue = ItQueue::factory()->create(['team_id' => $team->id, 'name' => 'W14 synthetic technical work', 'filter_rules' => [
            'is_default' => true, 'site_ids' => [$fixtures['sites']['a'], $fixtures['sites']['b']],
            'cover_user_id' => $cover->id, 'default_assignee_user_id' => $tech->id,
        ]]);
        $deliver = static function (DeviceEvent $event, string $expectedItStatus = 'applied'): DeviceEventSignalOutbox {
            $outbox = $event->signalOutbox()->sole();
            app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
            app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
            $outbox->refresh();
            w06BrowserRequire($outbox->status === 'sent' && $outbox->it_status === $expectedItStatus, 'Synthetic monitoring delivery failed.');

            return $outbox;
        };
        $failTicketInsert = false;
        ItTicket::creating(function () use (&$failTicketInsert): void {
            if ($failTicketInsert) {
                throw new RuntimeException('Synthetic browser fixture: interrupted technical ticket insert.');
            }
        });
        $failedDelivery = static function ($outbox, object $sourceJob, object $itJob) use (&$failTicketInsert): void {
            app()->call([$sourceJob, 'handle']);
            $before = ItTicket::query()->count();
            $failTicketInsert = true;
            try {
                app()->call([$itJob, 'handle']);
                throw new LogicException('The synthetic ticket interruption did not run.');
            } catch (RuntimeException $exception) {
                w06BrowserRequire($exception->getMessage() === 'Synthetic browser fixture: interrupted technical ticket insert.',
                    'Unexpected failure while preparing the synthetic delivery.');
            } finally {
                $failTicketInsert = false;
            }
            $outbox->refresh();
            w06BrowserRequire($outbox->status === 'sent' && $outbox->it_status === 'failed'
                && $outbox->it_outcome_code === 'processing_failed' && $outbox->it_attempts === 1
                && ItTicket::query()->count() === $before,
                'Synthetic failed delivery must preserve its real source intent and roll back technical work.');
        };
        $retryCases = [];
        $historyPending = [];
        $cases = [];
        SignalRule::query()->create([
            'name' => 'W14 isolated technical-check policy',
            'signal_source_id' => SignalSource::query()->where('slug', 'security_devices')->sole()->id,
            'signal_type_id' => SignalType::query()->where('code', 'device_monitor_failed')->sole()->id,
            'signal_type_code' => 'device_monitor_failed', 'priority' => 1, 'output_severity' => 'medium',
            'output_tier' => 2, 'is_active' => true, 'deduplicate' => true, 'dedup_window_minutes' => 30,
        ]);
        $nativeCases = ['direct', 'recovered', 'urgent', 'other_site', 'urgent_recovered', 'retry', 'retry_stale', 'retry_access'];
        $nativeCases = [...$nativeCases, 'condition_direct', 'condition_recovered', 'condition_urgent', 'condition_retry', 'condition_other_site'];
        foreach (array_merge($nativeCases, array_map(fn (int $number): string => 'history_pending_'.$number, range(1, 13))) as $case) {
            $technical = str_starts_with($case, 'condition_');
            $scenario = $technical ? substr($case, strlen('condition_')) : $case;
            $siteId = (int) $fixtures['sites'][$scenario === 'other_site' ? 'c' : 'a'];
            $urgent = in_array($scenario, ['urgent', 'urgent_recovered'], true);
            SignalRule::query()->where('signal_type_code', $technical ? 'device_monitor_failed' : 'device_offline')->update(['output_severity' => $urgent ? 'high' : 'medium']);
            $device = Device::factory()->itInfrastructure()->create(['name' => 'W14 '.$context['token'].' synthetic '.$case.' switch']);
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $siteId, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $tech->id]);
            $profile = MonitoringProfile::factory()->create(['failure_confirmations' => 1, 'recovery_confirmations' => 1,
                'failure_duration_seconds' => 0, 'recovery_duration_seconds' => 0]);
            $monitor = Monitor::factory()->create(['device_id' => $device->id, 'profile_id' => $profile->id, 'collector_id' => null,
                'name' => 'W14 synthetic '.$case.' availability', 'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy, 'kind' => $technical ? 'tls' : 'icmp', 'affects_availability' => ! $technical]);
            $time = CarbonImmutable::now()->subMinute()->startOfSecond();
            $failure = app(MonitoringObservationIngestor::class)->ingest($monitor,
                new ObservationInput('w14-'.$case.'-failed', MonitorState::Failed, $time), $siteId, (int) $device->id, null)->deviceEvent;
            w06BrowserRequire($failure !== null, 'The synthetic confirmed fault has no canonical source event.');
            if (str_starts_with($case, 'history_pending_')) {
                // A real acknowledged source with an undispatched IT intent,
                // bounded to13 fixtures so the authorized history spans two pages.
                $outbox = $failure->signalOutbox()->sole();
                app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
                $outbox->refresh();
                w06BrowserRequire($outbox->status === 'sent' && $outbox->it_status === 'pending'
                    && $outbox->it_attempts === 0 && empty($outbox->it_ticket_ids),
                    'Synthetic history fixture must retain an undispatched canonical IT intent.');
                $historyPending[] = $outbox->id;

                continue;
            }
            if (str_starts_with($scenario, 'retry')) {
                $outbox = $failure->signalOutbox()->sole();
                $failedDelivery($outbox, new DispatchDeviceEventSignalOutbox($outbox->id), new DispatchDeviceMonitoringTicket($outbox->id));
                $retryCases[$case] = ['source' => 'device', 'outbox_id' => $outbox->id, 'device_id' => $device->id, 'site_id' => $siteId];

                continue;
            }
            $outbox = $deliver($failure);
            $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
            if (in_array($scenario, ['recovered', 'urgent_recovered'], true)) {
                $recovery = app(MonitoringObservationIngestor::class)->ingest($monitor,
                    new ObservationInput('w14-'.$case.'-healthy', MonitorState::Healthy, $time->addSecond()), $siteId, (int) $device->id, null)->deviceEvent;
                w06BrowserRequire($recovery !== null, 'Synthetic recovery has no canonical source event.');
                $deliver($recovery);
            }
            $ticket->refresh();
            $snapshot = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole();
            w06BrowserRequire($snapshot->hasValidChecksum() && ($scenario === 'other_site' || $ticket->queue_id === $queue->id)
                && ($urgent ? $snapshot->control_room_alert_id !== null : $snapshot->control_room_alert_id === null),
                'Synthetic routing or evidence does not match its declared case.');
            if ($scenario === 'urgent_recovered') {
                $alert = $snapshot->alert;
                w06BrowserRequire($alert?->status === 'open' && ! empty($alert->context['monitoring_recoveries'])
                    && $ticket->status === 'open' && $ticket->monitoring_recovered_at !== null,
                    'Synthetic urgent recovery must preserve both operational and technical work.');
            }
            $cases[$case] = ['ticket_id' => $ticket->id, 'reference' => $ticket->reference, 'device_id' => $device->id,
                'alert_id' => $snapshot->control_room_alert_id, 'site_id' => $siteId, 'outbox_id' => $outbox->id,
                'status' => $ticket->status, 'status_reason' => $ticket->status_reason, 'evidence_version' => $snapshot->evidence_version];
        }

        foreach (['legacy_recovered', 'legacy_reordered'] as $case) {
            $reordered = $case === 'legacy_reordered';
            $siteId = (int) $fixtures['sites']['a'];
            $device = Device::factory()->itInfrastructure()->create(['name' => 'W14 '.$context['token'].' synthetic '.$case.' switch']);
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $siteId, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $tech->id]);
            $time = CarbonImmutable::now()->subMinute()->startOfSecond();
            $payload = ['monitor_correlation_key' => hash('sha256', 'synthetic-'.$case.'-'.$context['token']), 'site_id' => $siteId];
            $failure = DeviceEvent::query()->create(['device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
                'source' => 'oblivion_monitoring', 'occurred_at' => $time, 'payload' => $payload]);
            $outbox = $reordered ? null : $deliver($failure);
            $recovery = DeviceEvent::query()->create(['device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
                'source' => 'oblivion_monitoring', 'occurred_at' => $time->addSecond(), 'payload' => $payload]);
            $deliver($recovery, $reordered ? 'ignored' : 'applied');
            $outbox ??= $deliver($failure);
            $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
            $snapshot = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole();
            w06BrowserRequire($snapshot->hasValidChecksum()
                && ($reordered ? $snapshot->control_room_alert_id === null
                    : ($snapshot->alert?->status === 'open'
                        && data_get($snapshot->alert->context, 'monitoring_recoveries.legacy:'.$failure->id.'.verification_required') === true))
                && $ticket->status === 'open' && $ticket->monitoring_recovered_at !== null,
                'Synthetic legacy recovery must preserve operational and technical work with exact fault evidence.');
            $cases[$case] = ['ticket_id' => $ticket->id, 'reference' => $ticket->reference, 'device_id' => $device->id,
                'alert_id' => $snapshot->control_room_alert_id, 'site_id' => $siteId, 'outbox_id' => $outbox->id,
                'status' => $ticket->status, 'status_reason' => $ticket->status_reason, 'evidence_version' => $snapshot->evidence_version];
        }

        SignalSource::query()->updateOrCreate(['slug' => 'queclink_fleet'], [
            'name' => 'Synthetic Fleet source', 'vendor' => 'queclink', 'status' => 'active',
        ]);
        $fleetDeliver = function (FleetSignal $source): FleetSignalOutbox {
            $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $source->id)->sole();
            app()->call([new DispatchFleetSignalOutbox($outbox->id), 'handle']);
            app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']);
            w06BrowserRequire($outbox->fresh()->status === 'sent' && $outbox->fresh()->it_status === 'applied', 'Synthetic Fleet delivery did not complete.');

            return $outbox->fresh();
        };
        $anchor = CarbonImmutable::now()->startOfSecond();
        $previousTestNow = Carbon::getTestNow();
        try {
            foreach (['fleet_direct', 'fleet_recovered', 'fleet_urgent', 'fleet_retry'] as $case) {
                SignalRule::query()->updateOrCreate(['name' => 'W14 synthetic Fleet availability'], [
                    'signal_type_code' => 'fleet_device_offline', 'priority' => 1, 'is_active' => true,
                    'output_severity' => $case === 'fleet_urgent' ? 'high' : 'medium',
                    'output_tier' => 2, 'output_escalation_level' => 1, 'deduplicate' => true,
                ]);
                $siteId = (int) $fixtures['sites']['a'];
                $asset = Asset::factory()->vehicle()->create(['site_id' => $siteId, 'home_site_id' => null,
                    'client_id' => null, 'name' => 'W14 synthetic '.$case.' vehicle']);
                $uid = 'W14-'.$context['token'].'-'.$case;
                $device = Device::factory()->tracking()->create(['name' => 'W14 synthetic '.$case.' tracker',
                    'provider' => 'queclink', 'imei' => $uid, 'device_uid' => $uid]);
                DeviceAssetLink::query()->create([
                    'device_id' => $device->id, 'asset_id' => $asset->id,
                    'link_type' => LinkType::InstalledIn, 'linked_at' => $anchor->subHour(),
                ]);
                $ingest = function () use ($uid, $device): void {
                    $result = app(FleetTelemetryIngestService::class)->ingest('queclink', [
                        'imei' => $uid, 'gps_time' => now()->toISOString(), 'event_type' => 'heartbeat',
                    ], (int) $device->id);
                    w06BrowserRequire(($result['ok'] ?? false) === true, 'Synthetic Fleet heartbeat was rejected.');
                };
                Carbon::setTestNow($anchor->subMinutes(20));
                $ingest();
                Carbon::setTestNow($anchor->subMinute());
                (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
                $offline = FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.offline')->sole();
                if ($case === 'fleet_retry') {
                    $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $offline->id)->sole();
                    $failedDelivery($outbox, new DispatchFleetSignalOutbox($outbox->id), new DispatchFleetMonitoringTicket($outbox->id));
                    $retryCases[$case] = ['source' => 'fleet', 'outbox_id' => $outbox->id, 'device_id' => $device->id,
                        'asset_id' => $asset->id, 'site_id' => $siteId];

                    continue;
                }
                $outbox = $fleetDeliver($offline);
                if ($case === 'fleet_recovered') {
                    Carbon::setTestNow($anchor);
                    $ingest();
                    $fleetDeliver(FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.online')->sole());
                }
                $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
                $snapshot = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole();
                w06BrowserRequire($snapshot->hasValidChecksum() && $snapshot->evidence_version === 3
                    && $snapshot->device_event_id === null && (int) $snapshot->fleet_signal_id === (int) $offline->id
                    && $ticket->queue_id === $queue->id && $ticket->status === 'open'
                    && ($case === 'fleet_urgent' ? $snapshot->control_room_alert_id !== null : $snapshot->control_room_alert_id === null)
                    && ($case !== 'fleet_recovered' || $ticket->monitoring_recovered_at !== null),
                    'Synthetic Fleet ticket ownership, recovery or canonical evidence differs from its declared case.');
                $cases[$case] = ['ticket_id' => $ticket->id, 'reference' => $ticket->reference, 'device_id' => $device->id,
                    'asset_id' => $asset->id, 'alert_id' => $snapshot->control_room_alert_id, 'site_id' => $siteId,
                    'outbox_id' => $outbox->id, 'status' => $ticket->status, 'status_reason' => $ticket->status_reason, 'evidence_version' => 3];
            }
        } finally {
            Carbon::setTestNow($previousTestNow);
        }

        $handoffGrant = Permission::query()->firstOrCreate(['key' => 'controlRoom.alerts.manage'], [
            'description' => 'Manage Control Room alerts', 'group' => 'controlRoom', 'module' => 'Operations',
        ]);
        Role::query()->where('name', 'w06-browser-tech')->sole()->permissions()->syncWithoutDetaching([$handoffGrant->id]);
        $handoffService = ItService::query()->create(['key' => 'w14-handoff-network',
            'name' => 'W14 synthetic network service', 'is_active' => true]);
        $handoffs = [];
        foreach (['create', 'link', 'cancel', 'stale', 'other_site', 'private'] as $case) {
            $siteId = (int) $fixtures['sites'][$case === 'other_site' ? 'c' : 'a'];
            $alert = ControlRoomAlert::factory()->create(['site_id' => $siteId, 'source' => 'manual',
                'alert_type' => 'other', 'severity' => 'high', 'status' => 'open',
                'notes' => 'W14 '.$context['token'].' synthetic '.$case.' handoff. No operational action.',
                'context' => $case === 'private' ? ['normalized_data' => ['controlled_drug' => true]] : []]);
            $target = in_array($case, ['link', 'stale'], true) ? ItTicket::factory()->create([
                'site_id' => $siteId, 'is_organisation_wide' => false, 'source' => 'agent',
                'work_type' => 'incident', 'status' => 'open', 'requester_user_id' => $tech->id,
                'title' => 'W14 synthetic '.$case.' handoff target',
            ]) : null;
            $handoffs[$case] = ['alert_id' => $alert->id, 'site_id' => $siteId, 'ticket_id' => $target?->id];
        }

        return ['synthetic_only' => true, 'queue_id' => $queue->id, 'team_id' => $team->id, 'cases' => $cases,
            'handoffs' => $handoffs, 'handoff_service_id' => $handoffService->id, 'retry_cases' => $retryCases,
            'history_pending_outbox_ids' => $historyPending];
    });
}
