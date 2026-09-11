<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\It\Services\ItControlRoomHandoffService;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Presenters\MonitoringIncidentEvidencePresenter;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Jobs\DispatchFleetMonitoringTicket;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetSignalService;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->actor = User::factory()->create(['approved_at' => now()]);
    $this->role = Role::query()->create(['name' => 'handoff-'.Str::uuid(), 'label' => 'Handoff test', 'level' => 60, 'type' => 'custom']);
    foreach (['it.view', 'it.manage', 'controlRoom.alerts.manage'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $this->role->permissions()->attach($permission);
    }
    $this->actor->roles()->attach($this->role);
    HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    $this->alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id, 'status' => 'open',
        'severity' => 'high', 'source' => 'manual', 'alert_type' => 'other',
        'context' => ['private_context' => 'Do not copy operational details']]);
    $this->handoff = app(ItControlRoomHandoffService::class);
});

function handoffInput(array $overrides = []): array
{
    return ['viewer_user_id' => test()->actor->id, 'request_uuid' => (string) Str::uuid(),
        'alert_version' => test()->handoff->preview(test()->alert, test()->actor)['alert_version'],
        'action' => 'create', 'title' => 'Investigate the network connection',
        'description' => 'Check the network connection and record the result.',
        'category' => 'network', 'impact' => 'site', 'urgency' => 'high',
        'reason' => 'Operational response needs technical network investigation.', ...$overrides];
}

function handoffTarget(array $overrides = []): ItTicket
{
    return ItTicket::factory()->create(['site_id' => test()->site->id, 'is_organisation_wide' => false,
        'source' => 'agent', 'work_type' => 'incident', 'status' => 'open', ...$overrides]);
}

function handoffUrl(string $suffix = ''): string
{
    return '/it/control-room/alerts/'.test()->alert->id.'/handoff'.$suffix;
}

test('handoff HTTP preview is actor-bound private and contains no operational free text', function () {
    $ticket = handoffTarget();
    $this->actingAs($this->actor)->getJson(handoffUrl().'?viewer_user_id='.$this->actor->id)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.viewer_user_id', $this->actor->id)->assertJsonPath('data.alert_id', $this->alert->id)
        ->assertJsonPath('data.candidates.0.id', $ticket->id)->assertDontSee('Do not copy operational details');
    $this->getJson(handoffUrl().'?viewer_user_id='.($this->actor->id + 1))->assertForbidden()
        ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($ticket->title);
    $this->getJson(handoffUrl().'?viewer_user_id='.$this->actor->id.'&search='.str_repeat('a', 121))->assertUnprocessable();
});

test('handoff HTTP commit replay recovery and late cancellation return one canonical outcome', function () {
    $input = handoffInput();
    $saved = $this->actingAs($this->actor)->postJson(handoffUrl(), $input)->assertOk()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.outcome', 'created')
        ->assertHeader('Cache-Control', 'no-store, private');
    $ticketId = $saved->json('data.ticket.id');
    $this->postJson(handoffUrl(), $input)->assertOk()->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.ticket.id', $ticketId);
    $identity = ['viewer_user_id' => $this->actor->id];
    $this->getJson(handoffUrl('/commands/'.$input['request_uuid']).'?'.http_build_query($identity))
        ->assertOk()->assertJsonPath('data.ticket.id', $ticketId);
    $this->postJson(handoffUrl('/commands/'.$input['request_uuid'].'/cancel'), $identity)
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.ticket.id', $ticketId);
    expect(ItTicket::query()->count())->toBe(1)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
});

test('handoff HTTP unknown receipt is distinct from denial and cancellation blocks late submission', function () {
    $input = handoffInput();
    $path = handoffUrl('/commands/'.$input['request_uuid']);
    $this->actingAs($this->actor)->getJson($path.'?viewer_user_id='.$this->actor->id)->assertOk()
        ->assertJsonPath('status', 'unconfirmed')->assertJsonPath('data.request_uuid', $input['request_uuid'])
        ->assertJsonPath('data.viewer_user_id', $this->actor->id)->assertJsonPath('data.alert_id', $this->alert->id)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($path.'/cancel', ['viewer_user_id' => $this->actor->id])->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson(handoffUrl(), $input)->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItTicket::query()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
});

test('handoff HTTP stale source target and changed command intent cannot silently save', function () {
    $input = handoffInput();
    $this->alert->update(['status' => 'ack']);
    $this->actingAs($this->actor)->postJson(handoffUrl(), $input)->assertUnprocessable()->assertJsonValidationErrors('alert_version');
    $target = handoffTarget();
    $input = handoffInput(['action' => 'link', 'ticket_id' => $target->id, 'ticket_version' => $target->lock_version]);
    $target->update(['title' => 'Changed after review']);
    $this->postJson(handoffUrl(), $input)->assertConflict()->assertJsonPath('code', 'stale_ticket')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(ItTicketCommandReceipt::query()->count())->toBe(0);
    $input = handoffInput();
    $this->postJson(handoffUrl(), $input)->assertOk();
    $this->postJson(handoffUrl(), [...$input, 'title' => 'Different intent'])->assertConflict()
        ->assertJsonPath('code', 'command_conflict')->assertHeader('Cache-Control', 'no-store, private');
});

test('handoff HTTP failure hides debug text and does not flash submitted work before a safe retry', function () {
    config(['app.debug' => true]);
    $input = handoffInput(['description' => 'Private technical draft for this failed command.']);
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'it.ticket.control_room_handoff') {
            throw new RuntimeException('Secret simulated database binding');
        }
    });
    $this->actingAs($this->actor)->post(handoffUrl(), $input)->assertStatus(500)
        ->assertJsonPath('code', 'handoff_outcome_unknown')->assertHeader('Cache-Control', 'no-store, private')
        ->assertDontSee($input['description'])->assertDontSee('Secret simulated database binding')->assertSessionMissing('_old_input');
    expect(ItTicket::query()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
    $fail = false;
    $this->postJson(handoffUrl(), $input)->assertOk()->assertJsonPath('status', 'committed');
    $this->post(handoffUrl(), [...handoffInput(), 'category' => null])->assertUnprocessable()
        ->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('_old_input');
});

test('handoff HTTP denies source or actor changes on every operation without exposing receipts', function (string $change) {
    $input = handoffInput();
    $this->handoff->execute($this->alert, $this->actor, $input);
    match ($change) {
        'grant' => $this->role->permissions()->detach(Permission::where('key', 'controlRoom.alerts.manage')->value('id')),
        'site' => $this->alert->update(['site_id' => Site::factory()->create()->id]),
        'privacy' => $this->alert->update(['context' => ['normalized_data' => ['controlled_drug' => true]]]),
        'unapproved' => $this->actor->update(['approved_at' => null]),
    };
    $expected = in_array($change, ['grant', 'unapproved'], true) ? 403 : 404;
    $this->actingAs(User::query()->findOrFail($this->actor->id));
    $path = handoffUrl('/commands/'.$input['request_uuid']);
    foreach ([
        $this->getJson(handoffUrl().'?viewer_user_id='.$this->actor->id),
        $this->postJson(handoffUrl(), $input),
        $this->getJson($path.'?viewer_user_id='.$this->actor->id),
        $this->postJson($path.'/cancel', ['viewer_user_id' => $this->actor->id]),
    ] as $index => $response) {
        // The first revoked request returns 403 and invalidates the session;
        // subsequent requests are signed out and must return 401.
        $response->assertStatus($change === 'unapproved' && $index > 0 ? 401 : $expected)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee($input['title'])->assertJsonMissingPath('data.ticket');
    }
    expect(ItTicketCommandReceipt::query()->count())->toBe(1);
    if ($change === 'unapproved') {
        $this->assertGuest();
    }
})->with(['grant', 'site', 'privacy', 'unapproved']);

test('handoff HTTP signed-out requests return private JSON instead of a login page or old input', function () {
    $input = handoffInput();
    $this->post(handoffUrl(), $input)->assertUnauthorized()->assertJsonPath('code', 'session_expired')
        ->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('_old_input');
    expect(ItTicketCommandReceipt::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(0);
});

test('human creation uses canonical intake and one recoverable audited handoff without changing the alert', function () {
    $input = handoffInput();
    $result = $this->handoff->execute($this->alert, $this->actor, $input);
    $ticket = ItTicket::query()->sole();
    expect($result['status'])->toBe('committed')->and($result['data']['outcome'])->toBe('created')
        ->and($ticket->source)->toBe('agent')->and($ticket->requester_user_id)->toBe($this->actor->id)
        ->and($ticket->description)->toBe($input['description'])
        ->and($ticket->category)->toBe('network')->and($ticket->priority)->toBe('urgent')
        ->and($ticket->events()->where('type', 'created')->count())->toBe(1)
        ->and($ticket->links()->where('relationship', 'source_alert')->sole()->created_by_user_id)->toBe($this->actor->id)
        ->and($this->alert->fresh()->status)->toBe('open')
        ->and(AuditLog::where('action', 'it.ticket.created')->count())->toBe(1)
        ->and(AuditLog::where('action', 'it.ticket.control_room_handoff')->count())->toBe(1);
    expect($this->handoff->execute($this->alert, $this->actor, $input)['data']['replayed'])->toBeTrue()
        ->and($this->handoff->recover($this->alert, $this->actor, $input)['data']['ticket']['id'])->toBe($ticket->id)
        ->and($this->handoff->recover($this->alert, $this->actor, $input, true)['status'])->toBe('committed')
        ->and(ItTicket::query()->count())->toBe(1);
    $activity = app(ItTicketActivityPresenter::class)->present($ticket, $this->actor);
    expect(json_encode($activity))->not->toContain($input['reason'], 'Do not copy operational details');
    expect(app(ItTicketContextPresenter::class)->present($ticket, $this->actor)['alerts'][0]['id'])->toBe($this->alert->id);
});

test('existing ticket selection creates a canonical actor link and rejects another destination', function () {
    $ticket = handoffTarget();
    $input = handoffInput(['action' => 'link', 'ticket_id' => $ticket->id, 'ticket_version' => $ticket->lock_version]);
    expect($this->handoff->execute($this->alert, $this->actor, $input)['data']['outcome'])->toBe('linked')
        ->and(ItTicket::query()->count())->toBe(1)->and($ticket->fresh()->status)->toBe('open');
    $another = handoffTarget();
    expect(fn () => $this->handoff->execute($this->alert, $this->actor,
        handoffInput(['action' => 'link', 'ticket_id' => $another->id, 'ticket_version' => $another->lock_version])))
        ->toThrow(ValidationException::class);
    expect($another->links()->count())->toBe(0);
});

test('automatic work already linked to the alert is reused rather than copied by human creation', function () {
    $ticket = handoffTarget(['source' => 'system']);
    $ticket->links()->create(['relationship' => 'source_alert', 'linkable_type' => $this->alert->getMorphClass(),
        'linkable_id' => $this->alert->id, 'context' => ['system_principal' => ItTicketLinkService::MONITORING_PRINCIPAL,
            'operation' => ItTicketLinkService::MONITORING_OPERATION]]);
    $result = $this->handoff->execute($this->alert, $this->actor, handoffInput());
    expect($result['data']['outcome'])->toBe('existing')->and($result['data']['changed'])->toBeFalse()
        ->and($result['data']['ticket']['id'])->toBe($ticket->id)->and(ItTicket::query()->count())->toBe(1);
});

test('cancellation tombstone prevents late creation and does not expose another alert receipt', function () {
    $input = handoffInput();
    expect($this->handoff->recover($this->alert, $this->actor, $input, true)['status'])->toBe('cancelled')
        ->and($this->handoff->execute($this->alert, $this->actor, $input)['status'])->toBe('cancelled')
        ->and(ItTicket::query()->count())->toBe(0);
    $other = ControlRoomAlert::factory()->create(['site_id' => $this->site->id, 'source' => 'manual', 'alert_type' => 'other']);
    expect(fn () => $this->handoff->recover($other, $this->actor, $input))->toThrow(HttpException::class);
});

test('changed intent cannot replay a committed command', function () {
    $input = handoffInput();
    $this->handoff->execute($this->alert, $this->actor, $input);
    expect(fn () => $this->handoff->execute($this->alert, $this->actor, [...$input, 'title' => 'Different request']))
        ->toThrow(ItTicketCommandConflict::class);
});

test('stale source and target versions fail before writes', function () {
    $input = handoffInput();
    $this->alert->update(['status' => 'ack']);
    expect(fn () => $this->handoff->execute($this->alert, $this->actor, $input))->toThrow(ValidationException::class);
    $ticket = handoffTarget();
    $input = handoffInput(['action' => 'link', 'ticket_id' => $ticket->id, 'ticket_version' => $ticket->lock_version]);
    $ticket->update(['title' => 'Changed after selection']);
    expect(fn () => $this->handoff->execute($this->alert, $this->actor, $input))->toThrow(ItTicketVersionConflict::class);
    expect(ItTicketCommandReceipt::query()->count())->toBe(0);
});

test('mandatory handoff audit failure rolls back intake links and receipts and a retry succeeds', function () {
    $input = handoffInput();
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'it.ticket.control_room_handoff') {
            throw new RuntimeException('Isolated handoff audit failure');
        }
    });
    expect(fn () => $this->handoff->execute($this->alert, $this->actor, $input))->toThrow(RuntimeException::class)
        ->and(ItTicket::query()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
    $fail = false;
    expect($this->handoff->execute($this->alert, $this->actor, $input)['status'])->toBe('committed');
});

test('source permissions Site and controlled content are checked before preview or receipt disclosure', function (string $change) {
    $input = handoffInput();
    $this->handoff->execute($this->alert, $this->actor, $input);
    match ($change) {
        'grant' => $this->role->permissions()->detach(Permission::where('key', 'controlRoom.alerts.manage')->value('id')),
        'site' => $this->alert->update(['site_id' => Site::factory()->create()->id]),
        'privacy' => $this->alert->update(['context' => ['normalized_data' => ['controlled_drug' => true]]]),
    };
    expect(fn () => $this->handoff->preview($this->alert, $this->actor))->toThrow(HttpException::class)
        ->and(fn () => $this->handoff->recover($this->alert, $this->actor, $input))->toThrow(HttpException::class);
})->with(['grant', 'site', 'privacy']);

test('cross Site settled and merged candidates cannot be linked and are absent from search', function (string $state) {
    $ticket = handoffTarget(match ($state) {
        'site' => ['site_id' => Site::factory()->create()->id],
        'closed' => ['status' => 'closed'],
        'merged' => ['merged_into_ticket_id' => handoffTarget()->id],
    });
    $preview = $this->handoff->preview($this->alert, $this->actor, $ticket->reference);
    expect(array_column($preview['candidates'], 'id'))->not->toContain($ticket->id);
    $input = handoffInput(['action' => 'link', 'ticket_id' => $ticket->id, 'ticket_version' => $ticket->lock_version]);
    expect(fn () => $this->handoff->execute($this->alert, $this->actor, $input))->toThrow($state === 'site' ? HttpException::class : ValidationException::class);
})->with(['site', 'closed', 'merged']);

test('controlled source metadata remains concealed in existing IT context and human link service', function () {
    $ticket = handoffTarget();
    $this->alert->update(['context' => ['normalized_data' => ['controlled_drug' => true]]]);
    $ticket->links()->create(['relationship' => 'source_alert', 'linkable_type' => $this->alert->getMorphClass(), 'linkable_id' => $this->alert->id]);
    expect(app(ItTicketContextPresenter::class)->present($ticket, $this->actor)['alerts'])->toBe([])
        ->and(fn () => app(ItTicketLinkService::class)->link($ticket, $this->alert, 'source_alert', [], $this->actor->id))->toThrow(DomainException::class);
    $key = MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY;
    $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'emar', 'module' => 'Health']);
    $this->role->permissions()->attach($permission);
    $viewer = User::query()->findOrFail($this->actor->id);
    expect(app(ItTicketContextPresenter::class)->present($ticket, $viewer)['alerts'][0]['id'])->toBe($this->alert->id);
});

test('classification and original actor are validated before a handoff can create work', function (string $field) {
    $input = handoffInput([$field => $field === 'viewer_user_id' ? $this->actor->id + 100 : 'invalid']);
    expect(fn () => $this->handoff->execute($this->alert, $this->actor, $input))->toThrow(ValidationException::class)
        ->and(ItTicket::query()->count())->toBe(0);
})->with(['category', 'impact', 'urgency', 'viewer_user_id']);

/** Real owning-module observations and source acknowledgement; IT delivery stays queued. */
function handoffMonitoringSource(string $kind): array
{
    $site = test()->site;
    $actor = test()->actor;
    if ($kind === 'native') {
        test()->seed(SecurityDevicesSignalSeeder::class);
        SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'high']);
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $actor->id,
        ]);
        $profile = MonitoringProfile::factory()->create([
            'failure_confirmations' => 1, 'recovery_confirmations' => 1,
            'failure_duration_seconds' => 0, 'recovery_duration_seconds' => 0,
        ]);
        $monitor = Monitor::factory()->create([
            'device_id' => $device->id, 'profile_id' => $profile->id, 'collector_id' => null,
            'current_state' => MonitorState::Healthy,
            'effective_state' => MonitorState::Healthy, 'affects_availability' => true,
        ]);
        $observe = function (bool $healthy) use ($monitor, $site, $device) {
            test()->travel(1)->minutes();
            $event = app(MonitoringObservationIngestor::class)->ingest($monitor,
                new ObservationInput((string) Str::uuid(),
                    $healthy ? MonitorState::Healthy : MonitorState::Failed,
                    CarbonImmutable::now()), (int) $site->id, (int) $device->id, null)->deviceEvent;
            expect($event)->not->toBeNull();
            $outbox = $event->signalOutbox()->sole();
            app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);

            return $outbox->fresh();
        };
        $deliver = fn ($outbox) => app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    } else {
        SignalSource::query()->firstOrCreate(['slug' => 'queclink_fleet'], [
            'name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => 'active',
        ]);
        SignalRule::query()->create([
            'name' => 'Isolated handoff rule', 'signal_type_code' => 'fleet_device_offline', 'priority' => 1000,
            'is_active' => true, 'output_severity' => 'high', 'output_tier' => 2, 'output_escalation_level' => 1, 'deduplicate' => true,
        ]);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $site->id, 'home_site_id' => null, 'client_id' => null]);
        $identifier = 'handoff-fleet-'.Str::uuid();
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink', 'imei' => $identifier, 'device_uid' => $identifier,
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id, 'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn, 'linked_at' => now()->subHour(),
        ]);
        $heartbeat = function () use ($device): void {
            $result = app(FleetTelemetryIngestService::class)->ingest('queclink', [
                'imei' => $device->imei, 'event_type' => 'heartbeat', 'gps_time' => now()->toISOString(),
            ], (int) $device->id);
            expect($result['ok'])->toBeTrue();
        };
        $heartbeat();
        $observe = function (bool $healthy) use ($heartbeat, $asset) {
            test()->travel($healthy ? 1 : 16)->minutes();
            if ($healthy) {
                $heartbeat();
            } else {
                (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
            }
            $event = FleetSignal::query()->where('asset_id', $asset->id)
                ->where('signal_type', $healthy ? 'device.online' : 'device.offline')->latest('id')->firstOrFail();
            $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $event->id)->sole();
            app()->call([new DispatchFleetSignalOutbox($outbox->id), 'handle']);

            return $outbox->fresh();
        };
        $deliver = fn ($outbox) => app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']);
    }
    $outbox = $observe(false);
    expect($outbox->status)->toBe('sent')->and($outbox->it_status)->toBe('pending');
    test()->alert = ControlRoomAlert::query()->findOrFail($outbox->it_scope['alert_id'] ?? $outbox->it_scope['correlated_alert_id']);

    return [$outbox, $observe, $deliver];
}

test('queued monitoring binds the real human selection and preserves recovery replay and later episodes', function (string $kind, bool $recoveryFirst) {
    [$offline, $observe, $deliver] = handoffMonitoringSource($kind);
    $target = handoffTarget();
    $humanTitle = $target->title;
    $input = handoffInput(['action' => 'link', 'ticket_id' => $target->id, 'ticket_version' => $target->lock_version]);
    $this->handoff->execute($this->alert, $this->actor, $input);
    $presenter = app(MonitoringIncidentEvidencePresenter::class);
    expect($presenter->forAlert($this->alert, $this->actor)['linked_it_work']['id'])->toBe($target->id);
    if ($recoveryFirst) {
        $online = $observe(true);
        $deliver($online);
    }
    $deliver($offline);
    if (! $recoveryFirst) {
        $online = $observe(true);
        $deliver($online);
    }
    $deliver($offline);
    $deliver($online);
    $target->refresh();
    $link = $target->links()->where('relationship', 'source_alert')->sole();
    $snapshot = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $target->id)->sole();
    expect(ItTicket::query()->count())->toBe(1)->and($offline->fresh()->it_ticket_ids)->toBe([$target->id])
        ->and($offline->fresh()->it_outcome_code)->toBe('ticket_updated')->and($target->source)->toBe('agent')
        ->and($target->title)->toBe($humanTitle)->and($target->status)->toBe('open')
        ->and($target->monitoring_recovered_at)->not->toBeNull()
        ->and($target->events()->where('type', 'created_from_monitoring')->count())->toBe(0)
        ->and($target->events()->where('type', 'control_room_handoff')->count())->toBe(1)
        ->and($target->events()->where('type', 'monitoring_handoff_bound')->count())->toBe(1)
        ->and($target->events()->where('type', 'monitoring_recovered')->count())->toBe(1)
        ->and($link->created_by_user_id)->toBe($this->actor->id)
        ->and($link->context['handoff']['source'])->toBe(ItControlRoomHandoffService::SOURCE)
        ->and($snapshot->hasValidChecksum())->toBeTrue()
        ->and($presenter->forAlert($this->alert->fresh(), $this->actor)['linked_it_work']['id'])->toBe($target->id);
    $target->update(['status' => 'closed', 'closed_at' => now()]);
    $deliver($offline);
    expect(ItTicket::query()->count())->toBe(1)->and($target->fresh()->status)->toBe('closed');
    $later = $observe(false);
    $deliver($later);
    expect($later->fresh()->it_status)->toBe('applied')->and(ItTicket::query()->count())->toBe(2)
        ->and($later->fresh()->it_ticket_ids)->not->toBe([$target->id]);
})->with(['native', 'fleet'])->with([false, true]);

test('a human looking source link without a committed handoff receipt cannot redirect automatic delivery', function (string $kind) {
    [$outbox, , $deliver] = handoffMonitoringSource($kind);
    $target = handoffTarget();
    $target->links()->create(['relationship' => 'source_alert', 'linkable_type' => $this->alert->getMorphClass(),
        'linkable_id' => $this->alert->id, 'created_by_user_id' => $this->actor->id,
        'context' => ['source' => ItControlRoomHandoffService::SOURCE, 'operation' => ItControlRoomHandoffService::OPERATION,
            'site_id' => $this->site->id]]);
    expect(app(MonitoringIncidentEvidencePresenter::class)->forAlert($this->alert, $this->actor)['linked_it_work'])->toBeNull();
    $deliver($outbox);
    expect($outbox->fresh()->it_status)->toBe('applied')->and($outbox->fresh()->it_ticket_ids)->not->toBe([$target->id])
        ->and($target->events()->where('type', 'monitoring_handoff_bound')->count())->toBe(0);
})->with(['native', 'fleet']);

test('a handoff merged before queued delivery requires reconciliation rather than creating another ticket', function (string $kind) {
    [$outbox, , $deliver] = handoffMonitoringSource($kind);
    $target = handoffTarget();
    $this->handoff->execute($this->alert, $this->actor,
        handoffInput(['action' => 'link', 'ticket_id' => $target->id, 'ticket_version' => $target->lock_version]));
    $destination = handoffTarget();
    $target->update(['merged_into_ticket_id' => $destination->id]);
    $deliver($outbox);
    expect($outbox->fresh()->it_status)->toBe('unroutable')->and(ItTicket::query()->count())->toBe(2)
        ->and($target->events()->where('type', 'monitoring_handoff_bound')->count())->toBe(0);
})->with(['native', 'fleet']);
