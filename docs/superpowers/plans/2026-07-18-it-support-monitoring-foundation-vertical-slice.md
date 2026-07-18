# IT Support Monitoring Foundation Vertical Slice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first production-shaped native monitoring path from idempotent observations through confirmed device state, canonical Control Room correlation, one linked IT incident, confirmed recovery, and visible ticket context.

**Architecture:** Extend the canonical Security & Devices `Device` and existing `DeviceEvent → SignalProcessingService → ControlRoomAlert` path. Add focused monitoring records and a transactional observation ingestor, then attach IT work through typed links and an after-commit listener; monitoring recovery resolves the operational alert while leaving technician ticket resolution under human control.

**Tech Stack:** PHP 8.4, Laravel 13, Eloquent/MySQL, Pest 4, Inertia 2, React 19, TypeScript, Vitest 4

**Design source:** `docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md`

**Completion ledger:** `docs/it-support-security-devices-completion-goal.md`

---

## File structure and responsibilities

### Monitoring domain

- Create `app/Domain/Monitoring/Enums/MonitorKind.php` for protocol/check classification.
- Create `app/Domain/Monitoring/Enums/MonitorState.php` for honest health states.
- Create `app/Domain/Monitoring/Data/ObservationInput.php` for the runtime-to-control-plane observation contract.
- Create `app/Domain/Monitoring/Data/ObservationResult.php` for idempotency and transition outcomes.
- Create `app/Domain/Monitoring/Models/MonitoringCollector.php` for optional site collector identity and heartbeat state.
- Create `app/Domain/Monitoring/Models/MonitoringProfile.php` for confirmation and schedule policy.
- Create `app/Domain/Monitoring/Models/Monitor.php` for an applied check and its current/pending state.
- Create `app/Domain/Monitoring/Models/MonitorObservation.php` for append-only observation evidence.
- Create `app/Domain/Monitoring/Services/MonitoringObservationIngestor.php` for transactional idempotency, confirmation, device projection, and DeviceEvent emission.

### IT and Control Room integration

- Create `app/Models/ItTicketLink.php` for stable typed links from a ticket to canonical records.
- Create `app/Domain/It/Services/ItTicketLinkService.php` for tenant-safe idempotent linking.
- Create `app/Listeners/It/CreateOrUpdateMonitoringTicket.php` for monitoring failure/recovery work orchestration after commit.
- Create `app/Domain/It/Presenters/ItTicketContextPresenter.php` for permission-aware ticket context.
- Modify `app/Models/ItTicket.php` to add service-management metadata and link relationships.
- Modify `app/Services/ControlRoom/SignalProcessingService.php` to resolve the canonical offline alert on an online recovery signal.
- Modify `app/Observers/DeviceEventObserver.php` to route recovery without creating a second “device online” alert.
- Modify `app/Providers/EventServiceProvider.php` to register the IT monitoring listener.
- Modify `app/Providers/AppServiceProvider.php` to add stable morph aliases.
- Modify `app/Http/Controllers/It/ItTicketController.php` to return linked context.
- Create `resources/js/components/it/ticket-linked-context.tsx` and modify `resources/js/pages/it/tickets/show.tsx` to display the canonical device and alert.

### Persistence and tests

- Create `database/migrations/2026_07_18_100001_create_monitoring_foundation_tables.php`.
- Create `database/migrations/2026_07_18_100002_extend_it_work_and_create_ticket_links.php`.
- Create `database/factories/MonitoringCollectorFactory.php`, `MonitoringProfileFactory.php`, `MonitorFactory.php`, and `MonitorObservationFactory.php`.
- Create `tests/Feature/Monitoring/MonitoringSchemaTest.php`.
- Create `tests/Feature/Monitoring/MonitoringObservationIngestorTest.php`.
- Create `tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php`.
- Create `tests/Feature/It/ItMonitoringTicketIntegrationTest.php`.
- Extend `tests/Feature/It/ItTicketWorkspaceTest.php`.
- Create `resources/js/components/it/__tests__/ticket-linked-context.test.tsx`.

## Task 1: Add native monitoring schema and domain models

**Files:**

- Create: `database/migrations/2026_07_18_100001_create_monitoring_foundation_tables.php`
- Create: `app/Domain/Monitoring/Enums/MonitorKind.php`
- Create: `app/Domain/Monitoring/Enums/MonitorState.php`
- Create: `app/Domain/Monitoring/Models/MonitoringCollector.php`
- Create: `app/Domain/Monitoring/Models/MonitoringProfile.php`
- Create: `app/Domain/Monitoring/Models/Monitor.php`
- Create: `app/Domain/Monitoring/Models/MonitorObservation.php`
- Create: `database/factories/MonitoringCollectorFactory.php`
- Create: `database/factories/MonitoringProfileFactory.php`
- Create: `database/factories/MonitorFactory.php`
- Create: `database/factories/MonitorObservationFactory.php`
- Test: `tests/Feature/Monitoring/MonitoringSchemaTest.php`

- [ ] **Step 1: Write the failing schema/model test**

```php
<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores collector profile monitor and honest current state', function () {
    expect(Schema::hasColumns('monitoring_collectors', [
        'tenant_id', 'collector_uuid', 'site_id', 'status', 'last_seen_at', 'config',
    ]))->toBeTrue();
    expect(Schema::hasColumns('monitoring_profiles', [
        'tenant_id', 'failure_confirmations', 'recovery_confirmations', 'stale_after_seconds',
    ]))->toBeTrue();
    expect(Schema::hasColumns('monitors', [
        'tenant_id', 'device_id', 'profile_id', 'collector_id', 'kind', 'current_state',
        'pending_state', 'pending_count', 'affects_availability', 'last_observation_at',
    ]))->toBeTrue();

    $device = Device::factory()->itInfrastructure()->create();
    $collector = MonitoringCollector::factory()->create(['tenant_id' => $device->tenant_id]);
    $profile = MonitoringProfile::factory()->create(['tenant_id' => $device->tenant_id]);
    $monitor = Monitor::factory()->create([
        'tenant_id' => $device->tenant_id,
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => $collector->id,
        'kind' => MonitorKind::Icmp,
        'current_state' => MonitorState::Unknown,
    ]);

    expect($monitor->kind)->toBe(MonitorKind::Icmp)
        ->and($monitor->current_state)->toBe(MonitorState::Unknown)
        ->and($monitor->device->is($device))->toBeTrue()
        ->and($monitor->profile->is($profile))->toBeTrue()
        ->and($monitor->collector->is($collector))->toBeTrue();
});
```

- [ ] **Step 2: Run the schema/model test and verify RED**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitoringSchemaTest.php
```

Expected: FAIL because monitoring tables, enums, models, and factories do not exist.

- [ ] **Step 3: Add the state and kind enums**

```php
<?php

namespace App\Domain\Monitoring\Enums;

enum MonitorState: string
{
    case Pending = 'pending';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Stale = 'stale';
    case Suppressed = 'suppressed';
    case NotApplicable = 'not_applicable';

    public function isFailure(): bool
    {
        return in_array($this, [self::Degraded, self::Failed], true);
    }
}
```

```php
<?php

namespace App\Domain\Monitoring\Enums;

enum MonitorKind: string
{
    case Icmp = 'icmp';
    case Tcp = 'tcp';
    case Dns = 'dns';
    case Http = 'http';
    case Tls = 'tls';
    case Snmp = 'snmp';
    case SnmpInterface = 'snmp_interface';
    case Provider = 'provider';
    case Collector = 'collector';
}
```

- [ ] **Step 4: Add the migration with tenant-scoped indexes and idempotent observations**

The migration must create:

```php
Schema::create('monitoring_collectors', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->uuid('collector_uuid');
    $table->string('name');
    $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
    $table->string('status')->default('pending');
    $table->timestamp('last_seen_at')->nullable();
    $table->json('config')->nullable();
    $table->timestamps();
    $table->unique(['tenant_id', 'collector_uuid'], 'monitoring_collectors_tenant_uuid_uq');
    $table->index(['tenant_id', 'status'], 'monitoring_collectors_tenant_status_idx');
});

Schema::create('monitoring_profiles', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->string('name');
    $table->text('description')->nullable();
    $table->unsignedInteger('interval_seconds')->default(60);
    $table->unsignedSmallInteger('failure_confirmations')->default(3);
    $table->unsignedSmallInteger('recovery_confirmations')->default(2);
    $table->unsignedInteger('stale_after_seconds')->default(300);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->unique(['tenant_id', 'name'], 'monitoring_profiles_tenant_name_uq');
});

Schema::create('monitors', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
    $table->foreignId('profile_id')->constrained('monitoring_profiles')->restrictOnDelete();
    $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
    $table->string('kind');
    $table->string('name');
    $table->string('target');
    $table->json('config')->nullable();
    $table->string('current_state')->default('unknown');
    $table->string('pending_state')->nullable();
    $table->unsignedSmallInteger('pending_count')->default(0);
    $table->boolean('affects_availability')->default(false);
    $table->boolean('is_enabled')->default(true);
    $table->timestamp('last_observation_at')->nullable();
    $table->timestamp('last_state_changed_at')->nullable();
    $table->timestamp('suppressed_until')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'current_state'], 'monitors_tenant_state_idx');
    $table->index(['device_id', 'is_enabled'], 'monitors_device_enabled_idx');
});

Schema::create('monitor_observations', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
    $table->string('source_key');
    $table->string('state');
    $table->decimal('value', 20, 6)->nullable();
    $table->string('unit')->nullable();
    $table->unsignedInteger('latency_ms')->nullable();
    $table->text('message')->nullable();
    $table->json('metrics')->nullable();
    $table->timestamp('observed_at');
    $table->timestamp('ingested_at');
    $table->timestamps();
    $table->unique(['monitor_id', 'source_key'], 'monitor_observations_monitor_source_uq');
    $table->index(['monitor_id', 'observed_at'], 'monitor_observations_monitor_observed_idx');
});
```

- [ ] **Step 5: Implement focused models and factories**

Each model uses `HasFactory`, explicit fillable/casts, `scopeForTenant`, and only its direct relationships. `Monitor` casts `kind` and `current_state` to the enums. `MonitorObservation` uses immutable evidence semantics: no update helpers are exposed.

- [ ] **Step 6: Run the focused test and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitoringSchemaTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the monitoring foundation schema**

```powershell
git add database/migrations/2026_07_18_100001_create_monitoring_foundation_tables.php app/Domain/Monitoring database/factories/Monitoring* tests/Feature/Monitoring/MonitoringSchemaTest.php
git commit -m "feat(monitoring): add native monitoring foundation"
```

## Task 2: Ingest observations idempotently and confirm state transitions

**Files:**

- Create: `app/Domain/Monitoring/Data/ObservationInput.php`
- Create: `app/Domain/Monitoring/Data/ObservationResult.php`
- Create: `app/Domain/Monitoring/Services/MonitoringObservationIngestor.php`
- Test: `tests/Feature/Monitoring/MonitoringObservationIngestorTest.php`

- [ ] **Step 1: Write failing tests for idempotency, confirmation, unknown, and recovery**

The test file must prove:

```php
it('does not transition until the configured failure confirmation count', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $monitor->profile->update(['failure_confirmations' => 3]);

    $ingestor = app(MonitoringObservationIngestor::class);
    $ingestor->ingest($monitor, observation('fail-1', MonitorState::Failed));
    $ingestor->ingest($monitor->fresh(), observation('fail-2', MonitorState::Failed));

    expect($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'offline')->count())->toBe(0);

    $result = $ingestor->ingest($monitor->fresh(), observation('fail-3', MonitorState::Failed));

    expect($result->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'offline')->count())->toBe(1);
});

it('deduplicates the same runtime observation without incrementing confirmation', function () {
    $monitor = Monitor::factory()->create(['current_state' => MonitorState::Healthy]);
    $input = observation('same-key', MonitorState::Failed);

    $first = app(MonitoringObservationIngestor::class)->ingest($monitor, $input);
    $second = app(MonitoringObservationIngestor::class)->ingest($monitor->fresh(), $input);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($monitor->fresh()->pending_count)->toBe(1);
});

it('never converts unknown or stale input into healthy state', function () {
    $monitor = Monitor::factory()->create(['current_state' => MonitorState::Failed]);

    app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        observation('unknown-1', MonitorState::Unknown),
    );

    expect($monitor->fresh()->current_state)->toBe(MonitorState::Unknown);
});

it('emits one online event only after confirmed availability recovery', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Failed,
        'affects_availability' => true,
    ]);
    $monitor->profile->update(['recovery_confirmations' => 2]);

    $ingestor = app(MonitoringObservationIngestor::class);
    $ingestor->ingest($monitor, observation('up-1', MonitorState::Healthy));
    $ingestor->ingest($monitor->fresh(), observation('up-2', MonitorState::Healthy));

    expect($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'online')->count())->toBe(1);
});
```

Define the helper in the test file so every referenced function is concrete:

```php
function observation(string $sourceKey, MonitorState $state): ObservationInput
{
    return new ObservationInput(
        sourceKey: $sourceKey,
        state: $state,
        observedAt: now()->toImmutable(),
        latencyMs: $state === MonitorState::Healthy ? 8 : null,
        message: $state->value,
    );
}
```

- [ ] **Step 2: Run the ingestor tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitoringObservationIngestorTest.php
```

Expected: FAIL because the data objects and ingestor do not exist.

- [ ] **Step 3: Add immutable input and result data objects**

```php
final readonly class ObservationInput
{
    public function __construct(
        public string $sourceKey,
        public MonitorState $state,
        public CarbonImmutable $observedAt,
        public int|float|null $value = null,
        public ?string $unit = null,
        public ?int $latencyMs = null,
        public ?string $message = null,
        public array $metrics = [],
    ) {}
}
```

```php
final readonly class ObservationResult
{
    public function __construct(
        public MonitorObservation $observation,
        public bool $duplicate,
        public bool $stateChanged,
        public MonitorState $from,
        public MonitorState $to,
        public ?DeviceEvent $deviceEvent,
    ) {}
}
```

- [ ] **Step 4: Implement transactional ingestion with row locking**

`MonitoringObservationIngestor::ingest(Monitor $monitor, ObservationInput $input): ObservationResult` must:

1. start a database transaction;
2. lock and reload the monitor with profile/device;
3. return the existing observation with `duplicate=true` when `(monitor_id, source_key)` already exists;
4. create the observation and update `last_observation_at`;
5. transition `unknown` and `stale` immediately and never interpret them as success;
6. require `failure_confirmations` for `degraded` or `failed` and `recovery_confirmations` for `healthy`;
7. keep `pending_state/pending_count` when confirmation is incomplete;
8. clear pending state and update `last_state_changed_at` on transition;
9. emit one `DeviceEvent` for availability transitions only: `offline` on `failed`, `online` on `healthy` after failure;
10. include monitor, observation, from/to state, target, latency, and source key in the event payload.

The event creation code is:

```php
$deviceEvent = DeviceEvent::create([
    'device_id' => $locked->device_id,
    'event_type' => $to === MonitorState::Healthy ? 'online' : 'offline',
    'severity' => $to === MonitorState::Failed ? 'high' : 'info',
    'source' => 'oblivion_monitoring',
    'occurred_at' => $input->observedAt,
    'payload' => [
        'monitor_id' => $locked->id,
        'observation_id' => $observation->id,
        'from_state' => $from->value,
        'to_state' => $to->value,
        'target' => $locked->target,
        'latency_ms' => $input->latencyMs,
        'source_key' => $input->sourceKey,
    ],
]);
```

- [ ] **Step 5: Run focused tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitoringObservationIngestorTest.php
```

Expected: PASS with one offline and one online transition event in the relevant tests.

- [ ] **Step 6: Commit observation ingestion**

```powershell
git add app/Domain/Monitoring/Data app/Domain/Monitoring/Services tests/Feature/Monitoring/MonitoringObservationIngestorTest.php
git commit -m "feat(monitoring): confirm and ingest observations"
```

## Task 3: Resolve the canonical Control Room alert on monitoring recovery

**Files:**

- Modify: `app/Services/ControlRoom/SignalProcessingService.php`
- Modify: `app/Observers/DeviceEventObserver.php`
- Modify: `database/seeders/SecurityDevicesSignalSeeder.php`
- Test: `tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php`
- Test: `tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php`

- [ ] **Step 1: Write a failing end-to-end recovery test**

```php
it('resolves the offline alert and does not create a device-online alert', function () {
    $this->seed(SecurityDevicesSignalSeeder::class);
    $device = Device::factory()->itInfrastructure()->create();
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinute(),
    ]);
    $offline = ControlRoomAlert::where('device_id', $projection->id)->latest('id')->firstOrFail();

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'online',
        'severity' => 'info',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    expect($offline->fresh()->status)->toBe(ControlRoomAlert::STATUS_RESOLVED)
        ->and(data_get($offline->fresh()->context, 'resolution.source'))->toBe('monitoring_recovery')
        ->and(ControlRoomAlert::where('device_id', $projection->id)->count())->toBe(1)
        ->and(Signal::where('signal_type_code', 'device_online')->firstOrFail()->status)->toBe('processed');
});
```

- [ ] **Step 2: Run the recovery test and verify RED**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php
```

Expected: FAIL because the online event currently creates a separate Control Room alert.

- [ ] **Step 3: Add `processDeviceRecovery` to `SignalProcessingService`**

Add:

```php
public function processDeviceRecovery(Signal $signal): int
{
    if ($signal->signal_type_code !== 'device_online') {
        throw new InvalidArgumentException('Only device_online signals can use device recovery processing.');
    }

    return DB::transaction(function () use ($signal): int {
        $canonicalDeviceId = (int) data_get($signal->normalized_data, 'canonical_device_id');
        $alerts = ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'security_devices')
            ->where(function ($query) use ($signal, $canonicalDeviceId) {
                if ($signal->device_id) {
                    $query->where('device_id', $signal->device_id);
                }
                if ($canonicalDeviceId > 0) {
                    $query->orWhere('context->normalized_data->canonical_device_id', $canonicalDeviceId);
                }
            })
            ->whereHas('signals', fn ($query) => $query->where('signal_type_code', 'device_offline'))
            ->get();

        foreach ($alerts as $alert) {
            $this->resolveAlert(
                $alert,
                'Monitoring confirmed that the device recovered.',
                'monitoring_recovery',
                ['recovery_signal_id' => $signal->id],
            );
        }

        $signal->markProcessed(null, 'Resolved matching device-offline alerts.');

        return $alerts->count();
    });
}
```

Use a grouped predicate that does not emit an empty `where()` when both identifiers are absent; in that case, resolve zero alerts and mark the recovery signal processed.

- [ ] **Step 4: Route online events through recovery processing**

In `DeviceEventObserver::created()`:

```php
$signal = $this->processor->ingest($payload);
$alert = null;

if ($event->event_type === 'online') {
    $this->processor->processDeviceRecovery($signal);
} else {
    $alert = $this->processor->process($signal);
}
```

Keep `processed_at`, event dispatch, logging, and the `alertCreated` contract intact.

- [ ] **Step 5: Deactivate the online alert rule for fresh seeds**

Set the `device_online` rule to `is_active => false`. Recovery remains explicit even if a legacy environment still has the old active row because the observer no longer calls general alert processing for this signal.

- [ ] **Step 6: Run recovery and existing signal-pipeline tests**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php
```

Expected: PASS; offline still creates one alert, online resolves it, heartbeats remain suppressed, and unknown events still use the generic type.

- [ ] **Step 7: Commit recovery semantics**

```powershell
git add app/Services/ControlRoom/SignalProcessingService.php app/Observers/DeviceEventObserver.php database/seeders/SecurityDevicesSignalSeeder.php tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php
git commit -m "feat(monitoring): resolve device alerts on recovery"
```

## Task 4: Add service-management metadata and typed ticket links

**Files:**

- Create: `database/migrations/2026_07_18_100002_extend_it_work_and_create_ticket_links.php`
- Create: `app/Models/ItTicketLink.php`
- Create: `app/Domain/It/Services/ItTicketLinkService.php`
- Modify: `app/Models/ItTicket.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `database/factories/ItTicketFactory.php`
- Test: `tests/Feature/It/ItMonitoringTicketIntegrationTest.php`

- [ ] **Step 1: Write failing model and tenant-boundary tests**

```php
it('links one ticket idempotently to a canonical device and alert', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1]);
    $device = Device::factory()->create(['tenant_id' => 1]);
    $alert = ControlRoomAlert::factory()->create();
    $service = app(ItTicketLinkService::class);

    $first = $service->link($ticket, $device, 'affected_device');
    $second = $service->link($ticket, $device, 'affected_device');
    $service->link($ticket, $alert, 'source_alert');

    expect($first->is($second))->toBeTrue()
        ->and($ticket->links()->count())->toBe(2)
        ->and($ticket->linked('affected_device')->first()->linkable->is($device))->toBeTrue();
});

it('rejects a cross-tenant ticket link', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1]);
    $device = Device::factory()->create(['tenant_id' => 2]);

    expect(fn () => app(ItTicketLinkService::class)->link($ticket, $device, 'affected_device'))
        ->toThrow(DomainException::class, 'same tenant');
});

it('allows a system incident without a human requester', function () {
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => null,
        'source' => 'system',
        'work_type' => 'incident',
    ]);

    expect($ticket->requester)->toBeNull()
        ->and($ticket->work_type)->toBe('incident');
});
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/It/ItMonitoringTicketIntegrationTest.php --filter="links|requester"
```

Expected: FAIL because service-management columns and typed links do not exist.

- [ ] **Step 3: Add ticket fields and link table**

The migration must:

```php
Schema::table('it_tickets', function (Blueprint $table) {
    $table->foreignId('requester_user_id')->nullable()->change();
    $table->string('work_type')->default('incident')->after('source');
    $table->string('impact')->default('individual')->after('priority');
    $table->string('urgency')->default('normal')->after('impact');
    $table->string('status_reason')->nullable()->after('status');
    $table->string('waiting_reason')->nullable()->after('status_reason');
    $table->string('resolution_code')->nullable()->after('resolved_at');
    $table->text('resolution_summary')->nullable()->after('resolution_code');
    $table->timestamp('monitoring_recovered_at')->nullable()->after('resolution_summary');
    $table->index(['tenant_id', 'work_type', 'status'], 'it_tickets_tenant_type_status_idx');
});

Schema::create('it_ticket_links', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
    $table->string('relationship');
    $table->string('linkable_type');
    $table->unsignedBigInteger('linkable_id');
    $table->json('context')->nullable();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->unique(
        ['ticket_id', 'relationship', 'linkable_type', 'linkable_id'],
        'it_ticket_links_target_uq',
    );
    $table->index(
        ['tenant_id', 'linkable_type', 'linkable_id'],
        'it_ticket_links_tenant_target_idx',
    );
});
```

The down migration restores requester non-null only after failing explicitly if system tickets still exist; it must not silently invent requester identities.

- [ ] **Step 4: Add `ItTicketLink` and tenant-safe service**

```php
final class ItTicketLinkService
{
    public function link(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context = [],
        ?int $actorUserId = null,
    ): ItTicketLink {
        $targetTenantId = $this->targetTenantId($target);
        if ($targetTenantId === null) {
            throw new DomainException('The linked record tenant could not be resolved.');
        }
        if ($targetTenantId !== (int) $ticket->tenant_id) {
            throw new DomainException('Ticket links must remain in the same tenant.');
        }

        return $ticket->links()->firstOrCreate([
            'relationship' => $relationship,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ], [
            'tenant_id' => $ticket->tenant_id,
            'context' => $context,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    private function targetTenantId(Model $target): ?int
    {
        if (is_numeric($target->getAttribute('tenant_id'))) {
            return (int) $target->getAttribute('tenant_id');
        }

        if ($target instanceof ControlRoomAlert) {
            $target->loadMissing(['site:id,tenant_id', 'device.canonicalDevice:id,tenant_id']);

            return is_numeric($target->site?->tenant_id)
                ? (int) $target->site->tenant_id
                : (is_numeric($target->device?->canonicalDevice?->tenant_id)
                    ? (int) $target->device->canonicalDevice->tenant_id
                    : null);
        }

        return null;
    }
}
```

Add `links(): HasMany` and `linked(string $relationship): HasMany` to `ItTicket`. Add fillable/casts for new fields. Add morph aliases:

```php
'security_device' => \App\Domain\SecurityDevices\Models\Device::class,
'control_room_alert' => \App\Models\ControlRoomAlert::class,
```

- [ ] **Step 5: Run focused tests and existing IT schema tests**

Run:

```powershell
php artisan test tests/Feature/It/ItMonitoringTicketIntegrationTest.php tests/Feature/It/ItTicketingSchemaTest.php
```

Expected: PASS with no regression to existing ticket references, comments, events, watchers, or provisioning links.

- [ ] **Step 6: Commit typed work links**

```powershell
git add database/migrations/2026_07_18_100002_extend_it_work_and_create_ticket_links.php app/Models/ItTicket.php app/Models/ItTicketLink.php app/Domain/It/Services/ItTicketLinkService.php app/Providers/AppServiceProvider.php database/factories/ItTicketFactory.php tests/Feature/It/ItMonitoringTicketIntegrationTest.php
git commit -m "feat(it): add typed monitoring work links"
```

## Task 5: Create and update one IT incident from monitoring findings

**Files:**

- Create: `app/Listeners/It/CreateOrUpdateMonitoringTicket.php`
- Modify: `app/Providers/EventServiceProvider.php`
- Test: `tests/Feature/It/ItMonitoringTicketIntegrationTest.php`

- [ ] **Step 1: Write failing failure, deduplication, and recovery tests**

Define this fixture helper at the top of the test file:

```php
function monitoredDevice(string $domain = 'it_infrastructure'): Device
{
    $device = Device::factory()->create(['domain' => $domain]);

    ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    return $device;
}
```

Seed `SecurityDevicesSignalSeeder` in `beforeEach`, set the queue connection to `sync`, and create real `DeviceEvent` records so the registered observer, signal processor, and listener are all exercised:

```php
beforeEach(function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
});

it('creates one linked system incident for a confirmed IT infrastructure outage', function () {
    $device = monitoredDevice();

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    $ticket = ItTicket::sole();
    expect($ticket->source)->toBe('system')
        ->and($ticket->work_type)->toBe('incident')
        ->and($ticket->category)->toBe('network')
        ->and($ticket->requester_user_id)->toBeNull()
        ->and($ticket->links()->where('relationship', 'source_alert')->exists())->toBeTrue()
        ->and($ticket->links()->where('relationship', 'affected_device')->exists())->toBeTrue();
});

it('adds repeated evidence to the same ticket instead of creating a duplicate', function () {
    $device = monitoredDevice();
    foreach (['first failure', 'repeated failure'] as $message) {
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'offline',
            'severity' => 'high',
            'source' => 'oblivion_monitoring',
            'occurred_at' => now(),
            'payload' => ['message' => $message],
        ]);
    }

    expect(ItTicket::count())->toBe(1)
        ->and(ItTicket::firstOrFail()->events()->where('type', 'monitoring_evidence_added')->count())->toBe(1);
});

it('marks the ticket monitoring recovered but leaves technician resolution open', function () {
    $device = monitoredDevice();
    foreach ([['offline', 'high'], ['online', 'info']] as [$eventType, $severity]) {
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'source' => 'oblivion_monitoring',
            'occurred_at' => now(),
        ]);
    }

    $ticket = ItTicket::sole();
    expect($ticket->status)->toBe('open')
        ->and($ticket->status_reason)->toBe('monitoring_recovered')
        ->and($ticket->monitoring_recovered_at)->not->toBeNull()
        ->and($ticket->events()->where('type', 'monitoring_recovered')->exists())->toBeTrue();
});

it('does not turn security or healthcare signals into automatic IT incidents without policy', function (string $domain) {
    $device = monitoredDevice($domain);
    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    expect(ItTicket::count())->toBe(0);
})->with(['security', 'iot_healthcare']);
```

- [ ] **Step 2: Run listener tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/It/ItMonitoringTicketIntegrationTest.php --filter="monitoring|outage|recovered|automatic"
```

Expected: FAIL because the listener does not exist.

- [ ] **Step 3: Implement the after-commit listener**

The listener implements `ShouldQueueAfterCommit` and uses queue `monitoring`. Its policy is explicit:

```php
private const AUTO_TICKET_DOMAINS = ['it_infrastructure'];
private const FAILURE_EVENTS = ['offline'];
private const RECOVERY_EVENTS = ['online'];
```

Failure handling must:

1. resolve the alert from `$event->signal->alert` or `$event->signal->correlatedAlert`;
2. ignore events without a canonical alert;
3. find a ticket by `source_alert` typed link;
4. create a system `incident` with no requester when absent;
5. map alert severity `critical/high/medium/low/info` to ticket priority `urgent/high/high/normal/low`;
6. stamp SLA due dates before saving;
7. link the canonical device and alert;
8. record `created_from_monitoring` or `monitoring_evidence_added` using `ItTicketEvent::record`.

Recovery handling must find active system incidents linked to the canonical device, set `monitoring_recovered_at/status_reason`, and record one idempotent recovery event. It must not set `resolved_at`, `closed_at`, or a terminal status.

- [ ] **Step 4: Register the listener**

```php
DeviceSignalPublished::class => [
    NotifyOnFallDetected::class,
    NotifyOnBedExit::class,
    NotifyOnMedicationCabinetOpen::class,
    CreateOrUpdateMonitoringTicket::class,
],
```

- [ ] **Step 5: Run the IT monitoring integration suite**

Run:

```powershell
php artisan test tests/Feature/It/ItMonitoringTicketIntegrationTest.php
```

Expected: PASS; one outage produces one linked ticket, repeated evidence does not duplicate it, recovery leaves the ticket open, and non-IT domains do not auto-ticket.

- [ ] **Step 6: Run the connected backend slice**

Run:

```powershell
php artisan test tests/Feature/Monitoring tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php tests/Feature/It/ItMonitoringTicketIntegrationTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit monitoring-to-ticket orchestration**

```powershell
git add app/Listeners/It/CreateOrUpdateMonitoringTicket.php app/Providers/EventServiceProvider.php tests/Feature/It/ItMonitoringTicketIntegrationTest.php
git commit -m "feat(it): create incidents from monitoring findings"
```

## Task 6: Show linked device and Control Room context in the ticket workspace

**Files:**

- Create: `app/Domain/It/Presenters/ItTicketContextPresenter.php`
- Modify: `app/Http/Controllers/It/ItTicketController.php`
- Create: `resources/js/components/it/ticket-linked-context.tsx`
- Create: `resources/js/components/it/__tests__/ticket-linked-context.test.tsx`
- Modify: `resources/js/pages/it/tickets/show.tsx`
- Test: `tests/Feature/It/ItTicketWorkspaceTest.php`

- [ ] **Step 1: Write a failing payload permission test**

Create a ticket linked to a same-tenant device and alert, request `GET /it/tickets/{ticket}` as an IT agent, and assert:

```php
->where('linked_context.devices.0.id', $device->id)
->where('linked_context.devices.0.name', $device->name)
->where('linked_context.devices.0.health_status', $device->health_status->value)
->where('linked_context.alerts.0.id', $alert->id)
->where('linked_context.alerts.0.status', $alert->status)
->where('linked_context.alerts.0.reference', $alert->reference_number)
```

Add a second assertion that a requester can see only context allowed by both the ticket policy and canonical device/alert policy; restricted fields such as raw config, payload, client data, and command capability are absent.

- [ ] **Step 2: Run the workspace test and verify RED**

Run:

```powershell
php artisan test tests/Feature/It/ItTicketWorkspaceTest.php --filter="linked context"
```

Expected: FAIL because `linked_context` is absent.

- [ ] **Step 3: Implement the presenter**

`ItTicketContextPresenter::present(ItTicket $ticket, User $viewer): array` loads typed links and returns:

```php
[
    'devices' => $deviceLinks->map(fn (ItTicketLink $link) => [
        'id' => $link->linkable->id,
        'uid' => $link->linkable->device_uid,
        'name' => $link->linkable->name,
        'domain' => $link->linkable->domain,
        'category' => $link->linkable->category,
        'status' => $link->linkable->status->value,
        'health_status' => $link->linkable->health_status->value,
        'last_seen_at' => $link->linkable->last_seen_at?->toIso8601String(),
        'href' => route('security-devices.devices.show', $link->linkable),
    ])->values()->all(),
    'alerts' => $alertLinks->map(fn (ItTicketLink $link) => [
        'id' => $link->linkable->id,
        'reference' => $link->linkable->reference_number,
        'alert_type' => $link->linkable->alert_type,
        'severity' => $link->linkable->severity,
        'status' => $link->linkable->status,
        'triggered_at' => $link->linkable->triggered_at?->toIso8601String(),
        'href' => route('control-room.alerts.show', $link->linkable),
    ])->values()->all(),
]
```

Filter each target with the canonical policy before mapping it. Do not return raw `context`, `config`, credentials, clinical payload, or command data.

- [ ] **Step 4: Add `linked_context` to the ticket payload**

Inject the presenter into `ItTicketController` and add:

```php
'linked_context' => $this->contextPresenter->present($ticket, $user),
```

The JSON drawer and Inertia page continue sharing the same payload.

- [ ] **Step 5: Write the failing React component test**

```tsx
it('shows monitoring recovery and canonical deep links without raw payloads', () => {
    render(
        <TicketLinkedContext
            recoveredAt="2026-07-18T01:00:00Z"
            devices={[deviceFixture]}
            alerts={[alertFixture]}
        />,
    );

    expect(screen.getByText('Monitoring recovered')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /core switch/i })).toHaveAttribute('href', '/security-devices/devices/10');
    expect(screen.getByRole('link', { name: /cr-000123/i })).toHaveAttribute('href', '/control-room/alerts/20');
    expect(screen.queryByText(/signal_payload/i)).not.toBeInTheDocument();
});
```

- [ ] **Step 6: Run the component test and verify RED**

Run:

```powershell
npx vitest run resources/js/components/it/__tests__/ticket-linked-context.test.tsx
```

Expected: FAIL because the component does not exist.

- [ ] **Step 7: Implement the context component and add it to the ticket rail**

Use existing status-pill, card, icon, date/time, focus, and external/deep-link patterns. The component must show:

- monitoring-recovered state separately from ticket resolution;
- device name, category, health, status, and last seen;
- alert reference, type, severity, status, and triggered time;
- clear links to canonical Security & Devices and Control Room records;
- an empty state only when there are no links.

- [ ] **Step 8: Run focused backend and frontend tests**

Run:

```powershell
php artisan test tests/Feature/It/ItTicketWorkspaceTest.php
npx vitest run resources/js/components/it/__tests__/ticket-linked-context.test.tsx
```

Expected: PASS.

- [ ] **Step 9: Commit ticket context UI**

```powershell
git add app/Domain/It/Presenters/ItTicketContextPresenter.php app/Http/Controllers/It/ItTicketController.php resources/js/components/it/ticket-linked-context.tsx resources/js/components/it/__tests__/ticket-linked-context.test.tsx resources/js/pages/it/tickets/show.tsx tests/Feature/It/ItTicketWorkspaceTest.php
git commit -m "feat(it): show monitoring context on tickets"
```

## Task 7: Verify the connected vertical slice and update the ledger

**Files:**

- Modify: `docs/it-support-security-devices-completion-goal.md`
- Modify: `docs/superpowers/plans/2026-07-18-it-support-monitoring-foundation-vertical-slice.md`

- [ ] **Step 1: Run the connected feature suites**

```powershell
php artisan test tests/Feature/Monitoring tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php tests/Feature/It
```

Expected: all tests pass with zero failures.

- [ ] **Step 2: Regenerate routes and run frontend verification**

```powershell
php artisan wayfinder:generate
npm test
npm run types
npm run build
npx vite build --ssr
```

Expected: each command exits 0. If `npm run types` reports missing generated routes, rerun Wayfinder once and record the exact remaining error rather than hiding it.

- [ ] **Step 3: Run formatting and diff checks**

```powershell
vendor/bin/pint --dirty
git diff --check
git status --short
```

Expected: formatting succeeds, diff check is clean, and status contains only this plan's intended files before the ledger commit.

- [ ] **Step 4: Update exact ledger evidence**

Mark only the requirements actually proven by this slice:

- L01 if confirmed failure and Control Room creation pass;
- L02 if one linked IT incident passes;
- L03 if repeated evidence is deduplicated;
- L04 if Control Room recovery and open technician ticket pass;
- the implemented subset of A04 and A08, with the explicit scope recorded;
- V01 and V08 remain unchecked unless their full master-goal definitions, not only this slice, have passed.

Add commit IDs and exact test counts to the Evidence log.

- [ ] **Step 5: Mark this plan's completed checkboxes**

Change each executed `- [ ]` in this plan to `- [x]`. Do not mark a step whose command did not produce its expected result.

- [ ] **Step 6: Commit plan and ledger evidence**

```powershell
git add docs/it-support-security-devices-completion-goal.md docs/superpowers/plans/2026-07-18-it-support-monitoring-foundation-vertical-slice.md
git commit -m "docs(monitoring): record vertical slice evidence"
```

- [ ] **Step 7: Review the foundation contract before the next plan**

Confirm the next plans can depend on these stable contracts:

- `Monitor` and `MonitorObservation` identity/state;
- `ObservationInput` idempotency;
- online recovery routing;
- `ItTicketLink` typed ownership;
- one monitoring-to-ticket orchestration path;
- permission-aware ticket context.

If a contract must change, amend it in a dedicated migration/API compatibility task rather than silently changing completed-plan evidence.

## Execution decision

The user has explicitly requested implementation in this session. No subagent delegation was requested, so execute this plan inline with `superpowers:executing-plans`, using `superpowers:test-driven-development` for each implementation task and checkpoints after each committed task.
