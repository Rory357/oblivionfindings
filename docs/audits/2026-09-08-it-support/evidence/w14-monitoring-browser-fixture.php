<?php

/** Opt-in, fingerprinted fixture for an owned fresh browser schema only. */

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\ControlRoom\SignalRule;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
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
        $cases = [];
        foreach (['direct', 'recovered', 'urgent', 'other_site', 'urgent_recovered'] as $case) {
            $siteId = (int) $fixtures['sites'][$case === 'other_site' ? 'c' : 'a'];
            $urgent = in_array($case, ['urgent', 'urgent_recovered'], true);
            SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => $urgent ? 'high' : 'medium']);
            $device = Device::factory()->itInfrastructure()->create(['name' => 'W14 '.$context['token'].' synthetic '.$case.' switch']);
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $siteId, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $tech->id]);
            $profile = MonitoringProfile::factory()->create(['failure_confirmations' => 1, 'recovery_confirmations' => 1,
                'failure_duration_seconds' => 0, 'recovery_duration_seconds' => 0]);
            $monitor = Monitor::factory()->create(['device_id' => $device->id, 'profile_id' => $profile->id, 'collector_id' => null,
                'name' => 'W14 synthetic '.$case.' availability', 'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy, 'affects_availability' => true]);
            $time = CarbonImmutable::now()->subMinute()->startOfSecond();
            $failure = app(MonitoringObservationIngestor::class)->ingest($monitor,
                new ObservationInput('w14-'.$case.'-failed', MonitorState::Failed, $time), $siteId, (int) $device->id, null)->deviceEvent;
            w06BrowserRequire($failure !== null, 'The synthetic confirmed fault has no canonical source event.');
            $outbox = $deliver($failure);
            $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
            if (in_array($case, ['recovered', 'urgent_recovered'], true)) {
                $recovery = app(MonitoringObservationIngestor::class)->ingest($monitor,
                    new ObservationInput('w14-'.$case.'-healthy', MonitorState::Healthy, $time->addSecond()), $siteId, (int) $device->id, null)->deviceEvent;
                w06BrowserRequire($recovery !== null, 'Synthetic recovery has no canonical source event.');
                $deliver($recovery);
            }
            $ticket->refresh();
            $snapshot = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole();
            w06BrowserRequire($snapshot->hasValidChecksum() && ($case === 'other_site' || $ticket->queue_id === $queue->id)
                && ($urgent ? $snapshot->control_room_alert_id !== null : $snapshot->control_room_alert_id === null),
                'Synthetic routing or evidence does not match its declared case.');
            if ($case === 'urgent_recovered') {
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

        return ['synthetic_only' => true, 'queue_id' => $queue->id, 'team_id' => $team->id, 'cases' => $cases];
    });
}
