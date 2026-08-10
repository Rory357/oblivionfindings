# Native Monitoring Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the production-shaped native monitoring runtime for direct and collector-backed discovery, protocol checks, durable ingestion, topology, retention, and operational visibility while preserving the canonical `Device` and Control Room correlation path.

**Architecture:** Keep the control plane and central runtime in this repository on Laravel/PHP 8.4, but run the runtime as separately supervised Redis queue workers and socket listeners so web and business queues are isolated. Use narrow capability adapters, signed versioned envelopes, MySQL control/current-state records, an external time-series store for samples, object storage for governed snapshots, and a separately deployable database-free PHP collector; all confirmed state changes still enter `DeviceEventObserver → SignalProcessingService` exactly once.

**Tech Stack:** PHP 8.4, Laravel 13, Redis queues, MySQL, InfluxDB 2 HTTP API, Laravel filesystem/object storage, ext-sodium, ext-snmp, phpseclib 3, Pest 4, Inertia 2, React 19, TypeScript, Vitest 4, Playwright

**Design source:** `docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md`

**Foundation dependency:** `docs/superpowers/plans/2026-07-18-it-support-monitoring-foundation-vertical-slice.md`

**Completion ledger:** `docs/it-support-security-devices-completion-goal.md`

> **Mandatory single-tenant boundary:** Oblivion Findings serves one operating organisation across all configured sites. Do not add organisation-partition identifiers to new runtime envelopes, delivery tables, collector contracts, correlation keys, fixtures, or acceptance criteria. Scope and authorise through approved sites and networks, canonical devices and ownership, roles and permissions, direct-object denial, and privacy rules. Legacy organisation-context columns in mature models are compatibility details only. See [`docs/architecture/single-tenant-application.md`](../../architecture/single-tenant-application.md).

---

## Delivery boundaries and decisions

- The central runtime is PHP 8.4 in this Laravel repository. Do not create a Go service; Go is not an approved or available runtime.
- Supervise named Redis workers for `monitoring-events`, `monitoring-checks`, `monitoring-discovery`, `monitoring-provider`, `monitoring-topology`, and `monitoring-maintenance`. Preserve the existing `monitoring` queue for Control Room/IT orchestration and do not route high-volume polling to it.
- `app/Domain/SecurityDevices/Models/Device.php` remains the only canonical device register. Discovery proposes, matches, merges, or splits identity evidence through `DeviceRegistryService`; it never creates a second device table.
- Confirmed runtime state changes continue through `MonitoringObservationIngestor`, `DeviceEvent`, `DeviceEventObserver`, and `SignalProcessingService`. Do not create another active-alert table or ticket correlation service.
- This plan covers observation, event, configuration, and projection envelopes. It defines a `CommandDispatchPort` that always rejects execution; command execution, reusable secret delivery, step-up, approval, break glass, and high-risk controls belong to the later device-command plan.
- Credentialed protocol code accepts an ephemeral `CredentialLease` from a narrow port. This plan tests that boundary with one-use fixtures and fails closed when no lease provider is configured; it does not build a secret store or expose reusable credentials.
- The collector has no application database connection and no embedded SQL store. Its durable state is an encrypted, bounded append-only spool plus a signed checkpoint file.
- Browser acceptance is desktop web only at 1440×900 and 1280×800. Responsive/mobile coverage remains outside this plan and must not be claimed from these checks.

## File structure and responsibilities

### Runtime contracts and durable delivery

- Create `config/monitoring.php` for queue names, version compatibility, signing key IDs, egress bounds, store endpoints, and retention tiers.
- Create `app/Domain/Monitoring/Contracts/EnvelopeSigner.php`, `TimeSeriesStore.php`, `SnapshotStore.php`, `CredentialLeaseProvider.php`, and `CommandDispatchPort.php` as narrow infrastructure seams.
- Create `app/Domain/Monitoring/Data/RuntimeEnvelope.php`, `CredentialLease.php`, `ProbeTarget.php`, and `ProtocolObservation.php` as immutable transport values.
- Create `app/Domain/Monitoring/Enums/RuntimeMessageType.php` and extend `MonitorKind.php` for the bounded protocol catalogue.
- Create `app/Domain/Monitoring/Models/MonitoringOutbox.php`, `MonitoringInbox.php`, `MonitoringConsumerCheckpoint.php`, and `MonitoringDeadLetter.php`.
- Create `app/Domain/Monitoring/Services/SodiumEnvelopeSigner.php`, `RuntimeEnvelopeCodec.php`, `MonitoringOutboxPublisher.php`, `MonitoringEnvelopeConsumer.php`, and `MonitoringReplayService.php`.
- Create `app/Domain/Monitoring/Jobs/PublishMonitoringOutbox.php` and `ConsumeMonitoringEnvelope.php`.

### Safe probes and workload execution

- Create `app/Domain/Monitoring/Contracts/ProbeAdapter.php` and one focused adapter per direct protocol under `app/Domain/Monitoring/Adapters/`.
- Create `app/Domain/Monitoring/Services/EgressPolicy.php`, `ProbeAdapterRegistry.php`, `MonitorCheckRunner.php`, and `MonitorScheduler.php`.
- Create `app/Domain/Monitoring/Jobs/RunMonitorCheck.php` and `ScheduleDueMonitors.php`.
- Create `app/Console/Commands/MonitoringListenSnmpTraps.php`, `MonitoringListenSyslog.php`, and `MonitoringListenFlow.php` as supervised UDP listeners which validate and enqueue but do not update domain state inline.

### Discovery, capabilities, topology, and retention

- Create discovery models/services under `app/Domain/Monitoring/Discovery/` and migrations for scopes, immutable runs, candidates, and identity evidence.
- Create capability contracts under `app/Services/Integration/Contracts/` and migrate UniFi, Milesight, and Queclink without enlarging `IntegrationAdapterInterface`.
- Create topology models/services under `app/Domain/Monitoring/Topology/` for immutable snapshots, evidence-bearing edges, dependency evaluation, and reviewed projection to existing `DeviceRelationship` records.
- Create time-series and snapshot infrastructure under `app/Infrastructure/Monitoring/`, with MySQL holding series pointers, current summaries, retention policy, and tombstones rather than raw high-volume samples.

### Collector and operations

- Create a standalone PHP 8.4 collector package under `collector/` with no Laravel or database dependency.
- Extend `MonitoringCollector`, discovery presenters, monitoring presenters, Integrations, Settings & audit, and the existing Security & Devices pages to show real runtime, protocol, topology, backlog, retention, and collector state.
- Create operational configuration under `ops/supervisor/` and runbooks under `docs/runbooks/monitoring/`.

## Task 1: Lock the PHP runtime, named queues, and command boundary

**Files:**

- Modify: `composer.json`
- Modify: `.env.example`
- Create: `config/monitoring.php`
- Create: `app/Domain/Monitoring/Contracts/CommandDispatchPort.php`
- Create: `app/Domain/Monitoring/Services/RejectingCommandDispatchPort.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Unit/Monitoring/MonitoringRuntimeConfigurationTest.php`

- [ ] **Step 1: Write the failing runtime configuration test**

```php
<?php

use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Services\RejectingCommandDispatchPort;

it('defines isolated runtime queues and rejects device commands', function () {
    expect(config('monitoring.queues'))->toBe([
        'events' => 'monitoring-events',
        'checks' => 'monitoring-checks',
        'discovery' => 'monitoring-discovery',
        'provider' => 'monitoring-provider',
        'topology' => 'monitoring-topology',
        'maintenance' => 'monitoring-maintenance',
        'orchestration' => 'monitoring',
    ])->and(app(CommandDispatchPort::class))->toBeInstanceOf(RejectingCommandDispatchPort::class);

    expect(fn () => app(CommandDispatchPort::class)->dispatch('door.unlock', 42, []))
        ->toThrow(LogicException::class, 'Device commands are outside the native monitoring runtime plan.');
});
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Unit/Monitoring/MonitoringRuntimeConfigurationTest.php`

Expected: FAIL because `config/monitoring.php` and `CommandDispatchPort` do not exist.

- [ ] **Step 3: Add the exact runtime configuration and rejecting command port**

```php
<?php

return [
    'queues' => [
        'events' => 'monitoring-events',
        'checks' => 'monitoring-checks',
        'discovery' => 'monitoring-discovery',
        'provider' => 'monitoring-provider',
        'topology' => 'monitoring-topology',
        'maintenance' => 'monitoring-maintenance',
        'orchestration' => 'monitoring',
    ],
    'contracts' => ['current' => 1, 'accepted' => [1]],
    'signing' => ['active_key_id' => env('MONITORING_SIGNING_KEY_ID'), 'keys' => []],
    'egress' => [
        'connect_timeout_seconds' => 5,
        'response_timeout_seconds' => 15,
        'max_response_bytes' => 1048576,
        'deny_cidrs' => ['0.0.0.0/8', '127.0.0.0/8', '100.100.100.200/32', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', 'fe80::/10', 'fd00:ec2::254/128', 'ff00::/8'],
    ],
    'retention' => ['raw_days' => 14, 'hourly_days' => 180, 'daily_days' => 1825],
];
```

```php
<?php

namespace App\Domain\Monitoring\Contracts;

interface CommandDispatchPort
{
    /** @param array<string, scalar|null> $parameters */
    public function dispatch(string $capability, int $deviceId, array $parameters): never;
}
```

```php
<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use LogicException;

final class RejectingCommandDispatchPort implements CommandDispatchPort
{
    public function dispatch(string $capability, int $deviceId, array $parameters): never
    {
        throw new LogicException('Device commands are outside the native monitoring runtime plan.');
    }
}
```

Change `composer.json` to require `php: ^8.4`, add `phpseclib/phpseclib: ^3.0`, and require `ext-sodium: *`. Add `ext-snmp` under Composer `suggest` because central/web development environments may not load it; Task 9 must fail closed at runtime when SNMP is enabled without the extension, and production readiness cannot be claimed until `php -m` proves it on every SNMP worker/collector host. Document `MONITORING_SIGNING_KEY_ID`, `MONITORING_SIGNING_KEYS`, `MONITORING_TIMESERIES_URL`, `MONITORING_TIMESERIES_ORG`, `MONITORING_TIMESERIES_BUCKET`, and `MONITORING_TIMESERIES_TOKEN` in `.env.example`. Bind `CommandDispatchPort::class` to `RejectingCommandDispatchPort::class` in `AppServiceProvider::register()`.

- [ ] **Step 4: Run the test and verify GREEN**

Run:

```powershell
composer validate --strict
php artisan test tests/Unit/Monitoring/MonitoringRuntimeConfigurationTest.php
```

Expected: Composer validation exits 0 and the Pest test passes.

- [ ] **Step 5: Commit**

```powershell
git add composer.json .env.example config/monitoring.php app/Domain/Monitoring/Contracts/CommandDispatchPort.php app/Domain/Monitoring/Services/RejectingCommandDispatchPort.php app/Providers/AppServiceProvider.php tests/Unit/Monitoring/MonitoringRuntimeConfigurationTest.php
git commit -m "chore(monitoring): define runtime queues and boundaries"
```

## Task 2: Persist versioned signed envelopes, outbox, inbox, checkpoints, and dead letters

**Files:**

- Create: `database/migrations/2026_07_21_100001_create_monitoring_delivery_tables.php`
- Create: `app/Domain/Monitoring/Enums/RuntimeMessageType.php`
- Create: `app/Domain/Monitoring/Data/RuntimeEnvelope.php`
- Create: `app/Domain/Monitoring/Contracts/EnvelopeSigner.php`
- Create: `app/Domain/Monitoring/Services/SodiumEnvelopeSigner.php`
- Create: `app/Domain/Monitoring/Services/RuntimeEnvelopeCodec.php`
- Create: `app/Domain/Monitoring/Models/MonitoringOutbox.php`
- Create: `app/Domain/Monitoring/Models/MonitoringInbox.php`
- Create: `app/Domain/Monitoring/Models/MonitoringConsumerCheckpoint.php`
- Create: `app/Domain/Monitoring/Models/MonitoringDeadLetter.php`
- Create: `tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php`

- [ ] **Step 1: Write failing contract and persistence tests**

```php
<?php

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores every durable delivery control and verifies a v1 envelope', function () {
    expect(Schema::hasColumns('monitoring_outbox', ['message_id', 'stream', 'source', 'sequence', 'envelope_bytes', 'published_at']))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_inbox', ['message_id', 'consumer', 'payload_hash', 'envelope_bytes', 'processed_at']))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_consumer_checkpoints', ['consumer', 'source', 'last_sequence', 'gap_from', 'gap_to']))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_dead_letters', ['message_id', 'site_id', 'consumer', 'reason_code', 'envelope_bytes', 'replay_count', 'resolved_at']))->toBeTrue();

    $envelope = RuntimeEnvelope::new(
        type: RuntimeMessageType::Observation,
        source: 'central:checks',
        sequence: 7,
        idempotencyKey: 'monitor:9:sample:7',
        payload: ['site_id' => 9, 'device_id' => 81, 'monitor_id' => 9, 'state' => 'healthy'],
    );
    $decoded = app(RuntimeEnvelopeCodec::class)->decode(app(RuntimeEnvelopeCodec::class)->encode($envelope));

    expect($decoded->schemaVersion)->toBe(1)
        ->and($decoded->sequence)->toBe(7)
        ->and($decoded->payload['site_id'])->toBe(9)
        ->and($decoded->traceId)->not->toBeEmpty();
});
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php`

Expected: FAIL because the delivery tables and envelope classes do not exist.

- [ ] **Step 3: Create the delivery schema with enforceable uniqueness**

Create four tables with these indexes:

```php
Schema::create('monitoring_outbox', function (Blueprint $table): void {
    $table->id();
    $table->uuid('message_id')->unique();
    $table->string('stream');
    $table->string('source');
    $table->unsignedBigInteger('sequence');
    $table->string('idempotency_key', 128);
    $table->mediumText('envelope_bytes');
    $table->timestamp('available_at');
    $table->timestamp('published_at')->nullable();
    $table->unsignedSmallInteger('attempts')->default(0);
    $table->text('last_error')->nullable();
    $table->timestamps();
    $table->unique(['source', 'sequence']);
    $table->unique(['source', 'idempotency_key']);
    $table->index(['stream', 'published_at', 'available_at']);
});
```

Create `monitoring_inbox` with unique `['consumer', 'message_id']` and `['consumer', 'source', 'idempotency_key']`, `monitoring_consumer_checkpoints` with unique `['consumer', 'source']`, and `monitoring_dead_letters` with indexes on `['consumer', 'resolved_at']`, nullable `site_id`, and `['site_id', 'resolved_at']` or equivalent site-worklist lookup. Every lookup and lock must include the consumer and source boundaries even when a UUID is globally unique. Outbox, inbox, and dead letters store the exact canonical signed transport string in `envelope_bytes`; JSON columns or Eloquent array casts are not authoritative because database normalisation would break byte-exact replay, signature verification, and payload hashes. Store a bounded reason code/message, replay count, replay timestamps, and resolving user ID; never store signing keys.

The DLQ `site_id` is trusted routing context supplied by authenticated intake, collector identity, or a canonical route lookup before parking; it is never inferred from an invalid or unauthenticated payload. A genuinely unscoped malformed message keeps `site_id = null` and is visible only through an explicit privileged operational path. Device, collector, and network references remain in the signed payload where required, and handlers validate them against canonical records before mutation.

`MonitoringInbox` enforces `payload_hash = sha256(envelope_bytes)` at creation. Its delivery identity, exact envelope bytes, and payload hash are immutable afterwards; only lifecycle fields such as `processed_at` may change. The model and its Eloquent builder enforce this across ordinary, quiet, bulk, counter, touch, insert, returning-insert, insert-from-query, and upsert paths; direct database writes are reserved for schema/migration operations. An existing row returned by `firstOrCreate` is compared with the incoming hash before the processed shortcut and before any handler runs.

- [ ] **Step 4: Implement the immutable envelope and signature codec**

```php
final readonly class RuntimeEnvelope
{
    public function __construct(
        public int $schemaVersion,
        public string $messageId,
        public RuntimeMessageType $type,
        public string $source,
        public int $sequence,
        public CarbonImmutable $occurredAt,
        public CarbonImmutable $ingestedAt,
        public string $idempotencyKey,
        public string $traceId,
        public array $payload,
        public string $keyId = '',
        public string $signature = '',
    ) {}

    public static function new(RuntimeMessageType $type, string $source, int $sequence, string $idempotencyKey, array $payload): self
    {
        $now = CarbonImmutable::now('UTC');
        return new self(1, (string) Str::orderedUuid(), $type, $source, $sequence, $now, $now, $idempotencyKey, (string) Str::orderedUuid(), $payload);
    }
}
```

`RuntimeEnvelopeCodec::encode()` must canonicalise keys recursively, JSON-encode with `JSON_THROW_ON_ERROR`, sign every field except `signature` with `sodium_crypto_auth`, then attach `key_id` and base64 signature. `decode()` must reject unknown versions, missing required fields, unknown key IDs, invalid signatures, invalid timestamps, and malformed canonical scope identifiers before returning `RuntimeEnvelope`. Message handlers, not the codec, validate any payload `site_id`, `device_id`, `monitor_id`, or `collector_uuid` against canonical ownership and the actor or collector's approved scope.

- [ ] **Step 5: Run focused and migration tests**

Run: `php artisan test tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php tests/Feature/Monitoring/MonitoringSchemaTest.php`

Expected: both files pass; tampered payload, unknown version, and unknown key tests each reject with a specific exception message.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_21_100001_create_monitoring_delivery_tables.php app/Domain/Monitoring/Enums/RuntimeMessageType.php app/Domain/Monitoring/Data/RuntimeEnvelope.php app/Domain/Monitoring/Contracts/EnvelopeSigner.php app/Domain/Monitoring/Services/SodiumEnvelopeSigner.php app/Domain/Monitoring/Services/RuntimeEnvelopeCodec.php app/Domain/Monitoring/Models/MonitoringOutbox.php app/Domain/Monitoring/Models/MonitoringInbox.php app/Domain/Monitoring/Models/MonitoringConsumerCheckpoint.php app/Domain/Monitoring/Models/MonitoringDeadLetter.php tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php
git commit -m "feat(monitoring): add durable signed message controls"
```

## Task 3: Publish and consume envelopes with idempotency, ordering, replay, and DLQ recovery

**Files:**

- Create: `app/Domain/Monitoring/Services/MonitoringOutboxPublisher.php`
- Create: `app/Domain/Monitoring/Services/MonitoringEnvelopeConsumer.php`
- Create: `app/Domain/Monitoring/Services/MonitoringReplayService.php`
- Create: `app/Domain/Monitoring/Services/MonitoringDeliveryRecoveryService.php`
- Create: `app/Domain/Monitoring/Contracts/RuntimeEnvelopeHandler.php`
- Create: `app/Domain/Monitoring/Services/RuntimeEnvelopeHandlerRegistry.php`
- Create: `app/Domain/Monitoring/Handlers/ObservationEnvelopeHandler.php`
- Create: `app/Domain/Monitoring/Database/MonitoringOutboxBuilder.php`
- Create: `app/Domain/Monitoring/Database/MonitoringDeadLetterBuilder.php`
- Create: `app/Domain/Monitoring/Jobs/PublishMonitoringOutbox.php`
- Create: `app/Domain/Monitoring/Jobs/ConsumeMonitoringEnvelope.php`
- Create: `app/Domain/Monitoring/Jobs/ReplayMonitoringDeadLetter.php`
- Create: `app/Console/Commands/MonitoringRecoverDelivery.php`
- Create: `app/Console/Commands/MonitoringReplayDeadLetter.php`
- Create: `database/migrations/2026_07_21_100002_add_replay_intent_to_monitoring_dead_letters.php`
- Modify: `app/Domain/Monitoring/Models/MonitoringOutbox.php`
- Modify: `app/Domain/Monitoring/Models/MonitoringDeadLetter.php`
- Modify: `app/Domain/Monitoring/Services/RuntimeEnvelopeCodec.php`
- Modify: `config/monitoring.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Monitoring/MonitoringDeliveryTest.php`

- [x] **Step 1: Write failing duplicate, gap, poison, and replay tests**

```php
it('processes once and parks sequence gaps without advancing the checkpoint', function () {
    $handler = Mockery::mock(ObservationEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    app()->instance(ObservationEnvelopeHandler::class, $handler);

    $one = signedEnvelope(sequence: 1, messageId: '018f0000-0000-7000-8000-000000000001');
    $three = signedEnvelope(sequence: 3, messageId: '018f0000-0000-7000-8000-000000000003');

    app(MonitoringEnvelopeConsumer::class)->consume('observation-projector', $one);
    app(MonitoringEnvelopeConsumer::class)->consume('observation-projector', $one);
    app(MonitoringEnvelopeConsumer::class)->consume('observation-projector', $three);

    expect(MonitoringInbox::count())->toBe(2)
        ->and(MonitoringConsumerCheckpoint::firstOrFail()->last_sequence)->toBe(1)
        ->and(MonitoringConsumerCheckpoint::firstOrFail()->gap_from)->toBe(2)
        ->and(MonitoringDeadLetter::where('reason_code', 'sequence_gap')->count())->toBe(1);
});
```

Add cases for duplicate message ID, duplicate idempotency key, unsupported version, invalid signature, handler exception retry exhaustion, replay after the missing sequence arrives, and permission denial when replaying a letter for an unapproved site or restricted device.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/Monitoring/MonitoringDeliveryTest.php`

Expected: FAIL because publisher, consumer, jobs, and replay service do not exist.

- [x] **Step 3: Implement transactional publish and ordered consume**

`MonitoringOutboxPublisher::stage()` allocates `sequence = max(source sequence) + 1` under a source-scoped shared Redis lock and writes the signed envelope in the same transaction as its domain change. A repeated source/idempotency key returns the existing row only when stream, type, source, key, and codec-canonical payload bytes match; it never reruns the domain change. Outbox delivery identity and exact signed bytes are immutable through ordinary and bulk Eloquent paths, and publish verifies the decoded message ID, source, sequence, and idempotency key against the durable row before queueing the consumer.

Immediate queue dispatch and the scheduled `monitoring:recover-delivery` pass use the same bounded lease-and-token claims. The every-minute recovery pass claims expired unpublished outbox rows and pending replay intents under row locks with `SKIP LOCKED` where supported. A process crash or queue outage therefore leaves durable recoverable work; stale jobs with replaced tokens no-op, acknowledged publishes alone set `published_at`, and finite job retries retain bounded safe failure text.

`MonitoringEnvelopeConsumer::consume()` must execute this order inside one transaction:

```php
$envelope = $codec->decode($encoded);
$incomingHash = hash('sha256', $encoded);
$inbox = MonitoringInbox::firstOrCreate(
    ['consumer' => $consumer, 'message_id' => $envelope->messageId],
    [
        'source' => $envelope->source,
        'sequence' => $envelope->sequence,
        'idempotency_key' => $envelope->idempotencyKey,
        'payload_hash' => $incomingHash,
        'envelope_bytes' => $encoded,
    ],
);
if (! hash_equals($inbox->payload_hash, $incomingHash)) {
    $this->park($envelope, $encoded, 'payload_invalid', 'message id reused with different payload');
    return;
}
if ($inbox->processed_at) {
    return;
}
$checkpoint = MonitoringConsumerCheckpoint::query()
    ->lockForUpdate()
    ->firstOrCreate(
        ['consumer' => $consumer, 'source' => $envelope->source],
        ['last_sequence' => 0],
    );
if ($envelope->sequence !== $checkpoint->last_sequence + 1) {
    $this->parkGap($checkpoint, $envelope, $encoded);
    return;
}
$handlers->for($envelope->type)->handle($envelope);
$inbox->forceFill(['processed_at' => now()])->save();
$checkpoint->forceFill(['last_sequence' => $envelope->sequence, 'gap_from' => null, 'gap_to' => null])->save();
```

Use stable reason codes `invalid_signature`, `unsupported_version`, `sequence_gap`, `scope_violation`, `site_scope_violation`, `handler_failed`, and `payload_invalid`. Redact payloads before logging; the permission-scoped DLQ record retains the signed envelope. Exact DLQ bytes receive a deterministic evidence fingerprint and site-aware dedupe key. Database uniqueness plus insert-or-ignore and exact conflict verification make poison and failed-hook delivery race-safe, while model and custom-builder guards keep every retained evidence field immutable. Semantic payload errors are classified as `payload_invalid`, and an exhausted stale duplicate cannot park `handler_failed` after the exact inbox item has already completed.

- [x] **Step 4: Add audited replay and discard commands**

`MonitoringReplayService::replay(User $actor, MonitoringDeadLetter $letter)` verifies runtime-operate permission plus current access to every referenced site and protected canonical target through `SecurityDevicesAccessService`. It persists an audited replay intent before dispatch, preserves the original signed bytes, and uses a generation token so stale jobs cannot claim a newer request. Successful consume, replay count, resolution actor/reason, intent clearing, and replay audit commit atomically. Queue or handler failure retains a recoverable pending intent; an authorised replacement operator can take over an orphaned or access-revoked request with a separate audit. `discard()` records actor, reason, and resolution time but never deletes the letter and cannot race a pending replay.

Observation handlers require the signed monitor/device/site context to agree with the canonical active device assignment and trusted routing site. Collector-bound monitors require the exact canonical collector UUID and collector site; direct monitors reject injected collector identity.

Run: `php artisan monitoring:replay-dead-letter 15 --actor=7 --reason="missing sequence restored"`

Expected during the test fixture: exit 0, one replay audit entry, and the checkpoint advances only after sequence 2 is consumed before sequence 3.

- [x] **Step 5: Run the delivery suite**

Run: `php artisan test tests/Feature/Monitoring/MonitoringDeliveryTest.php`

Expected: all delivery, retry, ordering, gap, poison, DLQ, and replay cases pass.

- [x] **Step 6: Commit**

```powershell
git add app/Domain/Monitoring/Contracts/RuntimeEnvelopeHandler.php app/Domain/Monitoring/Services/RuntimeEnvelopeHandlerRegistry.php app/Domain/Monitoring/Handlers/ObservationEnvelopeHandler.php app/Domain/Monitoring/Services/MonitoringOutboxPublisher.php app/Domain/Monitoring/Services/MonitoringEnvelopeConsumer.php app/Domain/Monitoring/Services/MonitoringReplayService.php app/Domain/Monitoring/Jobs/PublishMonitoringOutbox.php app/Domain/Monitoring/Jobs/ConsumeMonitoringEnvelope.php app/Console/Commands/MonitoringReplayDeadLetter.php tests/Feature/Monitoring/MonitoringDeliveryTest.php
git commit -m "feat(monitoring): make runtime delivery replayable"
```

**Task 3 evidence (2026-07-21):** committed locally as `a18d7a0ea`. Fresh delivery migrations passed. The focused delivery suite passed 29 tests / 188 assertions, and the combined Task 2-3 persistence, schema, single-application architecture, and Monitoring unit gate passed 75 tests / 529 assertions. The every-minute recovery schedule contract, PHP syntax, Pint, Composer validation/platform checks, diff checks, and forbidden partition/mobile term scan passed. Independent final review approved the crash recovery, generation-token replay, exact evidence immutability, payload classification, collector/Site binding, and signed substitution protections. No push or deployment was performed.

## Task 4: Enforce site, network, target, DNS, and SSRF egress boundaries

**Files:**

- Create: `app/Domain/Monitoring/Data/ProbeTarget.php`
- Create: `app/Domain/Monitoring/Exceptions/EgressDenied.php`
- Create: `app/Domain/Monitoring/Services/CidrMatcher.php`
- Create: `app/Domain/Monitoring/Services/EgressPolicy.php`
- Create: `tests/Unit/Monitoring/EgressPolicyTest.php`

- [x] **Step 1: Write failing allow/deny and DNS-rebinding tests**

```php
it('allows only every resolved address inside the canonical site and device scope', function () {
    $dns = fakeResolver([
        'switch.site.example' => ['10.44.8.10'],
        'rebind.site.example' => ['10.44.8.11', '169.254.169.254'],
    ]);
    $scopes = fakeCanonicalScopeResolver(siteId: 9, deviceId: 81, cidrs: ['10.44.0.0/16']);
    $policy = new EgressPolicy(new CidrMatcher, $dns, $scopes, config('monitoring.egress'));

    expect($policy->authorise(9, 81, ProbeTarget::tcp('switch.site.example', 443))->addresses)->toBe(['10.44.8.10']);
    expect(fn () => $policy->authorise(9, 81, ProbeTarget::http('http://rebind.site.example/status')))
        ->toThrow(EgressDenied::class, 'resolved address outside scope');
    expect(fn () => $policy->authorise(9, 81, ProbeTarget::http('http://user:pass@switch.site.example/')))
        ->toThrow(EgressDenied::class, 'userinfo is forbidden');
});
```

Add tests for site mismatch, device ownership mismatch, an unapproved network, loopback, link-local metadata, multicast, IPv4-in-IPv6, an empty DNS answer, CIDR boundary addresses, ports outside the scope allowlist, redirect to a denied host, response-size cap, and timeout cap.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Unit/Monitoring/EgressPolicyTest.php`

Expected: FAIL because the target and egress policy classes do not exist.

- [x] **Step 3: Implement fail-closed egress authorisation**

```php
final readonly class ProbeTarget
{
    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public ?string $path,
    ) {}

    public static function tcp(string $host, int $port): self
    {
        return new self('tcp', $host, $port, null);
    }

    public static function http(string $url): self
    {
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user'], $parts['pass']) || ! isset($parts['scheme'], $parts['host'])) {
            throw new EgressDenied('invalid target or userinfo is forbidden');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new EgressDenied('scheme is forbidden');
        }
        return new self($scheme, $parts['host'], (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $parts['path'] ?? '/');
    }
}
```

Resolve A and AAAA records once, require every address to be inside an approved discovery-scope CIDR and outside the global deny list, carry the approved address set into the adapter, pin connections to that set, and re-authorise every redirect. Never let an adapter perform a second unverified hostname lookup.

The implementation must resolve canonical scope from active Device assignments rather than accepting a caller-built `ProbeScope`. All assignment evidence must collapse to exactly one active Site, including room, active client, current staff, and active vehicle category/site/home/client evidence. CIDR/port authority comes from a trusted `ApprovedProbeScopeProvider`; both that provider and DNS use rejecting production defaults until their owning later tasks bind real implementations. Only `EgressPolicy` may mint the private-construction `AuthorizedProbeTarget` carrying the pinned address set and caps. Reject credential-bearing URL query names so reusable secrets cannot enter the transport target. This task performs no probe network I/O; Task 5 owns adapter execution.

- [x] **Step 4: Run the policy tests**

Run: `php artisan test tests/Unit/Monitoring/EgressPolicyTest.php`

Expected: all boundary and rebinding cases pass.

- [x] **Step 5: Commit**

```powershell
git add app/Domain/Monitoring/Data/ProbeTarget.php app/Domain/Monitoring/Exceptions/EgressDenied.php app/Domain/Monitoring/Services/CidrMatcher.php app/Domain/Monitoring/Services/EgressPolicy.php tests/Unit/Monitoring/EgressPolicyTest.php
git commit -m "feat(monitoring): enforce probe egress scope"
```

**Task 4 verification evidence (2026-07-21, commit `d54576a93`):** The implementation now resolves active Device assignments through canonical Site, room, active client, current staff, and active vehicle category/site/home/client evidence; every current assignment must collapse to exactly one active Site before a trusted scope provider is consulted. Future assignments and staff profiles, inactive or missing targets, conflicting vehicle/client Sites, forged provider results, and raw resolver/provider errors fail closed. `ApprovedProbeScopeProvider` and `DnsResolver` have rejecting defaults. `EgressPolicy` validates configuration before DNS, resolves once, approves every address, denies special-use plus AWS and Alibaba metadata endpoints, enforces IPv4/IPv6 and mapped-address CIDRs, ports, network/broadcast rules, redirects, HTTPS downgrade, and transport caps, and is the only construction path for the immutable pinned-address target. Credential-like query names, including compact/camel, encoded, double-encoded, nested, and over-encoded variants, are rejected without retaining the value. No adapter or probe network I/O was added; Task 5 remains open.

The final deterministic regression passed 83 Monitoring unit/architecture tests with 2,813 assertions and 76 compatible Monitoring feature tests with 442 assertions (159 tests / 3,255 assertions total). The broad prebuilt feature run passed 78 tests and had one unrelated failure in `MonitoringRecoveryPipelineTest`: the disposable SQLite fixture lacks newer IT/Control Room columns and the MySQL-only `last_insert_id` allocator, so its observer intentionally catches the database error and leaves the alert open. The native isolated-MySQL gate produced no output and stalled in the repository test harness; only its exact PHP processes were terminated. Task-owned Pint, PHP syntax, Composer strict validation/platform requirements, diff checks, and forbidden partition/mobile/network-I/O scans passed. Independent review approved the final current-assignment, vehicle, secret-query, resolver-error, metadata, DNS, CIDR, redirect, construction, and rejecting-default boundaries.

## Task 5: Run direct ICMP, TCP, DNS, HTTP, and TLS checks on the checks queue

**Files:**

- Create: `app/Domain/Monitoring/Contracts/ProbeAdapter.php`
- Create: `app/Domain/Monitoring/Data/AuthorisedProbeContext.php`
- Create: `app/Domain/Monitoring/Data/ProtocolObservation.php`
- Create: `app/Domain/Monitoring/Adapters/IcmpProbeAdapter.php`
- Create: `app/Domain/Monitoring/Adapters/TcpProbeAdapter.php`
- Create: `app/Domain/Monitoring/Adapters/DnsProbeAdapter.php`
- Create: `app/Domain/Monitoring/Adapters/HttpProbeAdapter.php`
- Create: `app/Domain/Monitoring/Adapters/TlsProbeAdapter.php`
- Create: `app/Domain/Monitoring/Services/ProbeAdapterRegistry.php`
- Create: `app/Domain/Monitoring/Services/MonitorCheckRunner.php`
- Create: `app/Domain/Monitoring/Jobs/RunMonitorCheck.php`
- Modify: `app/Domain/Monitoring/Enums/MonitorKind.php`
- Create: `tests/Feature/Monitoring/DirectProbeAdaptersTest.php`
- Create: `tests/Feature/Monitoring/RunMonitorCheckTest.php`

- [x] **Step 1: Write failing protocol contract tests with local fakes**

```php
it('normalises bounded direct check results without exposing response bodies', function (MonitorKind $kind, array $config, string $unit) {
    $monitor = Monitor::factory()->create(['kind' => $kind, 'config' => $config]);
    $result = app(ProbeAdapterRegistry::class)->for($kind)->probe(approvedContext($monitor));

    expect($result->state)->toBe(MonitorState::Healthy)
        ->and($result->unit)->toBe($unit)
        ->and($result->evidence)->not->toHaveKeys(['body', 'authorization', 'cookie']);
})->with([
    'icmp' => [MonitorKind::Icmp, ['host' => '10.44.0.10'], 'ms'],
    'tcp' => [MonitorKind::Tcp, ['host' => '10.44.0.10', 'port' => 443], 'ms'],
    'dns' => [MonitorKind::Dns, ['server' => '10.44.0.53', 'name' => 'service.example', 'type' => 'A'], 'answers'],
    'http' => [MonitorKind::Http, ['url' => 'https://service.example/health', 'expected_status' => [200]], 'ms'],
    'tls' => [MonitorKind::Tls, ['host' => 'service.example', 'port' => 443, 'warn_days' => 30], 'days'],
]);
```

Use fake process, socket, DNS, and HTTP transports so the suite is deterministic. Add HTTP content-match, redirect re-authorisation, TLS hostname mismatch, expired certificate, DNS record mismatch, TCP refusal, ICMP loss, timeout, and maximum-body cases.

- [x] **Step 2: Run the adapter tests and verify RED**

Run: `php artisan test tests/Feature/Monitoring/DirectProbeAdaptersTest.php`

Expected: FAIL because probe contracts and adapters do not exist.

- [x] **Step 3: Implement the common result and five focused adapters**

```php
interface ProbeAdapter
{
    public function kind(): MonitorKind;
    public function probe(AuthorisedProbeContext $context): ProtocolObservation;
}

final readonly class ProtocolObservation
{
    public function __construct(
        public MonitorState $state,
        public CarbonImmutable $observedAt,
        public int|float|null $value,
        public ?string $unit,
        public ?int $latencyMs,
        public string $reasonCode,
        public array $evidence,
    ) {}
}
```

The ICMP adapter must call `ping` through Symfony Process argument arrays with one packet and the configured deadline, never a shell string. TCP uses `stream_socket_client` against an authorised numeric address. DNS sends a bounded UDP query to the authorised DNS server and parses only the requested record type. HTTP pins the authorised IP with the original host/SNI, limits redirects to three, rechecks every redirect, accepts an allowlisted status/content rule, and reads at most `max_response_bytes`. TLS opens a pinned TLS socket, enables peer and hostname verification, and returns days to expiry, issuer hash, SAN match, and protocol version without returning the certificate body.

- [x] **Step 4: Write the failing check-job integration test**

```php
it('runs on monitoring-checks and publishes one idempotent observation', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create(['kind' => MonitorKind::Tcp]);
    $job = new RunMonitorCheck($monitor->id, 'scheduled:2026-07-21T00:00:00Z');

    expect($job->queue)->toBe('monitoring-checks');
    $job->handle(app(MonitorCheckRunner::class));

    expect(MonitorObservation::where('source_key', "runtime:{$monitor->id}:scheduled:2026-07-21T00:00:00Z")->count())->toBe(1);
});
```

- [x] **Step 5: Implement the runner and check job**

`MonitorCheckRunner` must load the monitor with its canonical device, site, profile, collector, and active discovery scope; reject disabled, inactive, site-mismatched, ownership-mismatched, or out-of-scope records; authorise egress; select exactly one adapter by `MonitorKind`; convert `ProtocolObservation` to the existing `ObservationInput`; and call `MonitoringObservationIngestor`. `RunMonitorCheck` uses `$connection = 'redis'`, `$queue = 'monitoring-checks'`, `tries = 3`, timeout 30 seconds, and uniqueness by monitor plus schedule key.

- [x] **Step 6: Run direct-probe and existing lifecycle suites**

Run: `php artisan test tests/Feature/Monitoring/DirectProbeAdaptersTest.php tests/Feature/Monitoring/RunMonitorCheckTest.php tests/Feature/Monitoring/MonitoringObservationIngestorTest.php tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php`

Expected: all tests pass; existing failure/recovery still creates `DeviceEvent` and uses the canonical Control Room path.

- [ ] **Step 7: Commit**

```powershell
git add app/Domain/Monitoring/Contracts/ProbeAdapter.php app/Domain/Monitoring/Data/AuthorisedProbeContext.php app/Domain/Monitoring/Data/ProtocolObservation.php app/Domain/Monitoring/Adapters app/Domain/Monitoring/Services/ProbeAdapterRegistry.php app/Domain/Monitoring/Services/MonitorCheckRunner.php app/Domain/Monitoring/Jobs/RunMonitorCheck.php app/Domain/Monitoring/Enums/MonitorKind.php tests/Feature/Monitoring/DirectProbeAdaptersTest.php tests/Feature/Monitoring/RunMonitorCheckTest.php
git commit -m "feat(monitoring): add safe direct protocol checks"
```

**Task 5 verification evidence (2026-07-23, uncommitted shared worktree):** Direct ICMP, TCP, DNS, HTTP/HTTPS, and TLS adapters now accept only egress-authorised pinned targets and normalise bounded scalar evidence into the existing `ObservationInput` contract. ICMP uses argument-array Symfony Process execution, TCP/TLS/DNS use approved numeric addresses, HTTP uses `CURLOPT_RESOLVE` with redirects disabled at the transport and reauthorised individually by `EgressPolicy`, and response bodies/certificate material are never persisted. DNS expectation configuration fails closed; TLS returns expiry/SAN/protocol metadata without certificate content; retries short-circuit once the monitor/source key already exists. `MonitorCheckRunner` rejects disabled monitors, inactive profiles, collector-backed checks, unsupported kinds, conflicting targets, missing canonical Site provenance, and invalid schedules before network I/O. `RunMonitorCheck` uses Redis `monitoring-checks`, three attempts, a 30-second timeout, and monitor-plus-schedule uniqueness. The focused adapter suite passed 13 tests / 70 assertions and the runner suite passed 10 / 50. The final connected adapter, runner, observation, recovery, DeviceEvent, Control Room, and IT-ticket matrix passed 49 / 233. The direct-probe/egress/single-application architecture matrix passed 73 / 2,995. Composer strict validation, scoped PHP syntax, scoped Pint, active-surface partition vocabulary scan, runtime capability checks (PHP 8.4.16, sodium, OpenSSL, cURL, intl, socket streams, `CURLOPT_RESOLVE`, Symfony Process, and system ping), and `git diff --check` passed. Step 7, scheduling, discovery, SNMP, listeners, provider capabilities, topology, collectors, storage, operations UI, load/restore, desktop-browser acceptance, commit, push, and deployment remain open.

## Task 6: Schedule due checks without starving orchestration

**Files:**

- Create: `app/Domain/Monitoring/Data/MonitorScheduleResult.php`
- Create: `app/Domain/Monitoring/Services/MonitorScheduler.php`
- Create: `app/Domain/Monitoring/Jobs/ScheduleDueMonitors.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Monitoring/MonitorSchedulerTest.php`

- [x] **Step 1: Write failing due, disabled, overlap, and queue tests**

```php
it('dispatches each due monitor once to the checks queue', function () {
    Queue::fake();
    Monitor::factory()->create(['is_enabled' => true, 'last_observation_at' => now()->subMinutes(5)]);
    Monitor::factory()->create(['is_enabled' => false, 'last_observation_at' => null]);

    app(MonitorScheduler::class)->dispatchDue(now()->startOfMinute());
    app(MonitorScheduler::class)->dispatchDue(now()->startOfMinute());

    Queue::assertPushed(RunMonitorCheck::class, 1, fn ($job) => $job->queue === 'monitoring-checks');
});
```

Add collector assignment, inactive-profile, not-yet-due, canonical Site mismatch, inactive/archived/unassigned Site omission, scheduler lock, and 10,000-monitor chunking cases.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/Monitoring/MonitorSchedulerTest.php`

Expected: FAIL because the scheduler service and job do not exist.

- [x] **Step 3: Implement deterministic scheduling**

Compute each schedule key as `floor(now UTC epoch / interval_seconds) * interval_seconds`, take a Redis lock named `monitoring:schedule:{scheduleKey}`, select enabled monitors on active profiles in ID chunks of 500, and dispatch one unique job per monitor/schedule key. Direct monitors dispatch centrally; collector monitors publish a scoped collector configuration item rather than dispatching a central probe.

Register:

```php
app(Schedule::class)
    ->job(new ScheduleDueMonitors)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
```

- [x] **Step 4: Run schedule inspection and tests**

Run:

```powershell
php artisan test tests/Feature/Monitoring/MonitorSchedulerTest.php
php artisan schedule:list | Select-String 'ScheduleDueMonitors'
```

Expected: tests pass and schedule output shows a one-minute cadence.

Evidence recorded 2026-07-23:

- The RED run failed all eight initial scenarios because `MonitorScheduler` and `ScheduleDueMonitors` did not exist and no schedule was registered.
- The final scheduler contract covers deterministic interval buckets, enabled/active/due filtering, malformed-kind omission, canonical active Site resolution, matching enrolled collector assignment, raw-secret rejection in collector configuration, shared Redis locking, checks/orchestration queue isolation, and 10,000 due monitors in exactly twenty 500-row chunks.
- `php artisan schedule:list | Select-String 'ScheduleDueMonitors|monitoring:recover-delivery'` shows both the scheduler job and delivery recovery at one-minute cadence; the scheduler event has `onOneServer` and `withoutOverlapping` guards.
- The authoritative connected runtime matrix passed 91 tests / 3,324 assertions across scheduler, direct probes, delivery, ingestion, recovery, DeviceEvent, IT monitoring tickets, egress, and single-application architecture boundaries.
- Composer strict validation, PHP syntax, scoped Pint, active partition-vocabulary scan, and `git diff --check` passed. Step 5 remains open because no commit was authorised or created.

- [ ] **Step 5: Commit**

```powershell
git add app/Domain/Monitoring/Data/MonitorScheduleResult.php app/Domain/Monitoring/Services/MonitorScheduler.php app/Domain/Monitoring/Jobs/ScheduleDueMonitors.php routes/console.php tests/Feature/Monitoring/MonitorSchedulerTest.php
git commit -m "feat(monitoring): schedule isolated check workloads"
```

## Task 7: Add site- and network-scoped discovery runs, candidates, and identity evidence

**Files:**

- Create: `database/migrations/2026_07_21_100002_create_monitoring_discovery_tables.php`
- Create: `app/Domain/Monitoring/Discovery/Models/DiscoveryScope.php`
- Create: `app/Domain/Monitoring/Discovery/Models/DiscoveryRun.php`
- Create: `app/Domain/Monitoring/Discovery/Models/DiscoveryCandidate.php`
- Create: `app/Domain/Monitoring/Discovery/Models/DeviceIdentityEvidence.php`
- Create: `app/Domain/Monitoring/Discovery/Data/DiscoveredIdentity.php`
- Create: `app/Domain/Monitoring/Discovery/Data/IdentityMatchResult.php`
- Create: `app/Domain/Monitoring/Discovery/Services/DiscoveryScopeValidator.php`
- Create: `app/Domain/Monitoring/Discovery/Services/DeviceIdentityMatcher.php`
- Create: `app/Domain/Monitoring/Discovery/Services/DiscoveryCandidateService.php`
- Create: `database/factories/DiscoveryScopeFactory.php`
- Create: `database/factories/DiscoveryRunFactory.php`
- Create: `database/factories/DiscoveryCandidateFactory.php`
- Create: `tests/Feature/Monitoring/DiscoveryIdentityTest.php`

- [x] **Step 1: Write failing schema and identity-decision tests**

```php
it('auto-matches only immutable high-confidence evidence and queues ambiguity', function () {
    $scope = DiscoveryScope::factory()->create(['site_id' => 9, 'cidrs' => ['10.44.0.0/16']]);
    $device = Device::factory()->create(['serial_number' => 'SER-100']);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $scope->site_id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now(),
    ]);

    $matched = app(DeviceIdentityMatcher::class)->match($scope, new DiscoveredIdentity(
        provider: 'snmp', providerId: null, serialNumber: 'SER-100', macAddresses: ['00:11:22:33:44:55'],
        certificateFingerprint: null, hostname: 'switch-1', addresses: ['10.44.0.10'], fingerprint: 'cisco:c9300',
    ));
    $ambiguous = app(DeviceIdentityMatcher::class)->match($scope, discoveredIdentity(hostname: 'switch-1'));

    expect($matched->decision)->toBe('matched')->and($matched->deviceId)->toBe($device->id)
        ->and($ambiguous->decision)->toBe('review')->and($ambiguous->reasons)->toContain('hostname_is_mutable');
});
```

Add tests proving site mismatch, unapproved-network, and canonical-ownership rejection; exclusions taking precedence; provider ID and certificate identity confidence; MAC normalisation; address-history-only review; no-match proposal; and immutable run summaries.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/Monitoring/DiscoveryIdentityTest.php`

Expected: FAIL because discovery persistence and services do not exist.

- [x] **Step 3: Create the discovery schema**

`monitoring_discovery_scopes` stores site, optional collector, approved CIDRs, seed hosts, enabled protocols, exclusions, port bounds, rate limits, schedule, and lifecycle state. `monitoring_discovery_runs` stores immutable counts for found/matched/proposed/changed/excluded/failed/unresolved plus start/end and failure summary. `monitoring_discovery_candidates` stores proposed or matched canonical device ID, decision, confidence, reasons, evidence snapshot, review actor/time, and supersession link. `monitoring_device_identity_evidence` stores canonical device ID, evidence type, SHA-256 normalised value, source, first/last seen, confidence, and active/superseded state; unique keys include the canonical device, evidence source, and normalised value where required.

Do not store raw credentials or create a discovery-owned device identity.

- [x] **Step 4: Implement explicit identity scoring**

Use these deterministic rules in `DeviceIdentityMatcher`:

```php
$weights = [
    'provider_id' => 100,
    'serial_number' => 95,
    'certificate_fingerprint' => 95,
    'hardware_id' => 90,
    'mac_address' => 80,
    'device_fingerprint' => 55,
    'hostname' => 25,
    'address_history' => 15,
];
```

One unique immutable match at 90 or above is `matched`. Conflicting immutable evidence is `review`. Mutable-only evidence is always `review`. No evidence match is `proposed`. Record each reason and matched evidence hash so reviewers can understand the decision.

- [x] **Step 5: Implement reviewed adopt, merge, and split against canonical Device**

`DiscoveryCandidateService::adopt()` must call existing `DeviceRegistryService`, attach active identity evidence, and mark the candidate accepted. `merge($winner, $loser)` must validate compatible canonical ownership and site visibility, move evidence/monitors/assignments/history in a transaction, record old-to-new IDs, and soft-delete only the losing canonical `Device`. `split()` must create a canonical Device through `DeviceRegistryService`, move only selected evidence and dependent observations, and record the repair audit. Every operation is idempotent by candidate plus review action.

- [x] **Step 6: Run identity and device-registry regressions**

Run: `php artisan test tests/Feature/Monitoring/DiscoveryIdentityTest.php tests/Unit/SecurityDevices/DeviceRegistryServiceTest.php tests/Feature/SecurityDevices/DeviceControllerTest.php`

Expected: all tests pass; no table other than `devices` is presented as canonical identity.

Evidence (2026-07-23): the focused test began RED with 10 failures because the discovery schema and services did not exist. The required connected regression command then passed 49 tests / 269 assertions. It proves Site-scoped identity decisions, deterministic confidence, exclusions, canonical Device adoption, permission-gated merge/split repair, immutable completed summaries, and existing Device registry/controller behavior. The broader single-application architecture ratchet is tracked separately because concurrent HR and migration changes currently introduce unrelated findings; no commit was created in this step.

- [ ] **Step 7: Commit**

```powershell
git add database/migrations/2026_07_21_100002_create_monitoring_discovery_tables.php app/Domain/Monitoring/Discovery database/factories/DiscoveryScopeFactory.php database/factories/DiscoveryRunFactory.php database/factories/DiscoveryCandidateFactory.php tests/Feature/Monitoring/DiscoveryIdentityTest.php
git commit -m "feat(monitoring): add governed discovery identity"
```

## Task 8: Execute bounded discovery runs and produce candidates

**Files:**

- Create: `app/Domain/Monitoring/Discovery/Contracts/DiscoveryAdapter.php`
- Create: `app/Domain/Monitoring/Discovery/Contracts/DiscoveryThrottle.php`
- Create: `app/Domain/Monitoring/Discovery/Adapters/NetworkSeedDiscoveryAdapter.php`
- Create: `app/Domain/Monitoring/Discovery/Data/DiscoveryTarget.php`
- Create: `app/Domain/Monitoring/Discovery/Data/DiscoveryProbeResult.php`
- Create: `app/Domain/Monitoring/Discovery/Models/DiscoveryResult.php`
- Create: `app/Domain/Monitoring/Discovery/Services/DiscoveryRunner.php`
- Create: `app/Domain/Monitoring/Discovery/Services/NativeDiscoveryTokenBucket.php`
- Create: `app/Domain/Monitoring/Jobs/RunDiscoveryScope.php`
- Create: `app/Domain/Monitoring/Jobs/CompleteDiscoveryRun.php`
- Create: `database/migrations/2026_07_21_100003_create_monitoring_discovery_results_table.php`
- Create: `tests/Feature/Monitoring/DiscoveryRunTest.php`
- Modify: `app/Domain/Monitoring/Services/CidrMatcher.php`
- Modify: `app/Domain/Monitoring/Services/EgressPolicy.php`
- Modify: `app/Domain/Monitoring/Adapters/TlsProbeAdapter.php`
- Modify: `app/Domain/Monitoring/Data/TlsTransportResult.php`
- Modify: `app/Domain/Monitoring/Transports/NativeTlsTransport.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [x] **Step 1: Write failing scope/rate/exclusion/run-summary tests**

```php
it('records an immutable reconciled run and never probes exclusions', function () {
    Queue::fake();
    $scope = DiscoveryScope::factory()->create([
        'cidrs' => ['10.44.0.0/24'],
        'exclusions' => ['10.44.0.1', '10.44.0.128/25'],
        'max_targets_per_run' => 127,
        'packets_per_second' => 20,
    ]);

    $run = app(DiscoveryRunner::class)->start($scope, 'manual:user:7');

    expect($run->status)->toBe('queued')
        ->and($run->planned_targets)->toBe(127);
    Queue::assertPushed(RunDiscoveryScope::class, fn ($job) => $job->queue === 'monitoring-discovery');
});
```

Add overlapping-run, disabled scope, collector scope, site/network mismatch, maximum CIDR expansion, partial adapter failure, cancellation, immutable completed counts, and idempotent rerun tests.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/Monitoring/DiscoveryRunTest.php`

Expected: FAIL because discovery execution classes do not exist.

- [x] **Step 3: Implement bounded network discovery**

`NetworkSeedDiscoveryAdapter` may use only approved scope targets and the direct adapters from Task 5. Probe ICMP/TCP seeds first, then collect DNS/certificate identity, then enqueue SNMP/provider capability work when configured. Expand at most `max_targets_per_run`, enforce `packets_per_second` with a token bucket, skip exclusions before dispatch, and store a hashed target reference plus bounded failure code—not arbitrary response bodies.

`DiscoveryRunner` must lock one active run per scope, snapshot the scope configuration into the run, dispatch to `monitoring-discovery`, feed every result to `DeviceIdentityMatcher`, create or update one candidate per run/evidence set, and reconcile all seven summary counts before marking the run complete.

- [x] **Step 4: Run discovery tests**

Run: `php artisan test tests/Feature/Monitoring/DiscoveryRunTest.php tests/Feature/Monitoring/DiscoveryIdentityTest.php tests/Unit/Monitoring/EgressPolicyTest.php`

Expected: all tests pass and the recorded counts equal the candidate/result drill-down.

Evidence (2026-07-23): the focused suite began RED because `DiscoveryAdapter` did not exist. The implementation now locks one active run per scope, snapshots Site/network policy, caps expansion at 65,536 targets, jumps excluded ranges before probe dispatch, consumes one token per network attempt, stores only keyed target hashes and bounded outcome codes, resumes without re-probing completed targets, and reconciles found/matched/proposed/changed/excluded/failed/unresolved counts from result and candidate drill-downs. Cancellation is race-safe and collector scopes fail closed on the central worker until Tasks 15-16 provide the remote runtime. The exact Task 8 discovery/identity/egress matrix passed 93 tests / 2,818 assertions; the connected Tasks 5-8 monitoring matrix passed 128 / 3,425, including direct-probe and single-application architecture guards. The SNMP/provider capability work intentionally remains in Tasks 9 and 12. No commit, push, or deployment was performed.

- [ ] **Step 5: Commit**

```powershell
git add app/Domain/Monitoring/Discovery/Contracts/DiscoveryAdapter.php app/Domain/Monitoring/Discovery/Adapters/NetworkSeedDiscoveryAdapter.php app/Domain/Monitoring/Discovery/Services/DiscoveryRunner.php app/Domain/Monitoring/Jobs/RunDiscoveryScope.php app/Domain/Monitoring/Jobs/CompleteDiscoveryRun.php tests/Feature/Monitoring/DiscoveryRunTest.php
git commit -m "feat(monitoring): execute bounded discovery runs"
```

## Task 9: Add SNMPv3 polling, inventory, interface counters, and traps

**Files:**

- Create: `app/Domain/Monitoring/Contracts/CredentialLeaseProvider.php`
- Create: `app/Domain/Monitoring/Data/CredentialLease.php`
- Create: `app/Domain/Monitoring/Services/UnavailableCredentialLeaseProvider.php`
- Create: `app/Domain/Monitoring/Protocols/Snmp/SnmpTransport.php`
- Create: `app/Domain/Monitoring/Protocols/Snmp/NativeSnmpTransport.php`
- Create: `app/Domain/Monitoring/Adapters/SnmpV3ProbeAdapter.php`
- Create: `app/Domain/Monitoring/Discovery/Adapters/SnmpInventoryDiscoveryAdapter.php`
- Create: `app/Domain/Monitoring/Protocols/Snmp/SnmpTrapDecoder.php`
- Create: `app/Domain/Monitoring/Protocols/Snmp/SnmpTrapIntakeService.php`
- Create: `app/Domain/Monitoring/Protocols/Snmp/SnmpTrapScopeResolver.php`
- Create: `app/Domain/Monitoring/Protocols/Snmp/SnmpEngineReplayGuard.php`
- Create: `app/Domain/Monitoring/Handlers/EventEnvelopeHandler.php`
- Create: `app/Console/Commands/MonitoringListenSnmpTraps.php`
- Create: `database/migrations/2026_07_21_100004_create_monitoring_snmp_runtime_tables.php`
- Create: `database/migrations/2026_07_21_100004_01_create_monitoring_snmp_engine_states.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Domain/Monitoring/Data/ProbeTarget.php`
- Modify: `app/Domain/Monitoring/Services/ProbeAdapterRegistry.php`
- Modify: `app/Domain/Monitoring/Services/MonitorScheduler.php`
- Modify: `app/Domain/Monitoring/Services/MonitorCheckRunner.php`
- Modify: `app/Domain/Monitoring/Discovery/Adapters/NetworkSeedDiscoveryAdapter.php`
- Modify: `config/monitoring.php`
- Create: `tests/Unit/Monitoring/SnmpV3ProtocolTest.php`
- Create: `tests/Feature/Monitoring/SnmpMonitorCheckTest.php`
- Create: `tests/Feature/Monitoring/SnmpTrapIngestionTest.php`
- Create: `tests/Fixtures/monitoring/snmp/` fixture directory

- [x] **Step 1: Write failing SNMPv3 security and normalisation tests**

```php
it('requires authenticated encrypted SNMPv3 and normalises interface counters', function () {
    $lease = new CredentialLease('lease-1', now()->addMinute()->toImmutable(), [
        'security_name' => 'collector', 'auth_protocol' => 'SHA256', 'auth_secret' => 'fixture-auth',
        'privacy_protocol' => 'AES', 'privacy_secret' => 'fixture-privacy',
    ]);
    $result = snmpAdapter(transportFixture('interfaces.json'))->probe(snmpContext($lease));

    expect($result->state)->toBe(MonitorState::Healthy)
        ->and($result->evidence['interfaces'][0])->toMatchArray(['if_index' => 1, 'admin' => 'up', 'oper' => 'up'])
        ->and(json_encode($result))->not->toContain('fixture-auth')->not->toContain('fixture-privacy');
});
```

Add authentication failure, privacy failure, expired lease, v1/v2c disabled by default, recorded compatibility exception, counter rollover, device reboot/discontinuity, partial OID response, sensor units, inventory identity, and oversized walk cases.

- [x] **Step 2: Run SNMP tests and verify RED**

Run: `php artisan test tests/Unit/Monitoring/SnmpV3ProtocolTest.php tests/Feature/Monitoring/SnmpTrapIngestionTest.php`

Expected: FAIL because the lease seam, transport, adapter, decoder, and listener do not exist.

- [x] **Step 3: Implement the credential seam and SNMPv3 adapter**

```php
interface CredentialLeaseProvider
{
    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease;
}

final readonly class CredentialLease
{
    public function __construct(public string $leaseId, public CarbonImmutable $expiresAt, private array $material) {}
    public function material(): array
    {
        if ($this->expiresAt->isPast()) {
            throw new RuntimeException('Credential lease expired.');
        }
        return $this->material;
    }
}
```

Bind `CredentialLeaseProvider` to `UnavailableCredentialLeaseProvider`, which throws `Credential lease provider is not configured.` Production activation is owned by the later secrets/command plan. Tests inject one-use leases. `NativeSnmpTransport` requires ext-snmp, accepts SNMPv3 auth/privacy only, uses numeric authorised addresses, applies OID and varbind caps, and clears material references after each call. A v1/v2c call requires a site- and device-scoped compatibility-exception record with owner, reason, expiry, and migration status.

- [x] **Step 4: Implement trap intake as a supervised event workload**

`MonitoringListenSnmpTraps` binds only the configured interface/port, caps datagrams at 65,507 bytes, resolves sender IP to one active approved site/network scope, decodes allowlisted v1/v2/v3 trap fields, rejects unauthenticated v3 traps, and publishes a signed `event` envelope to `monitoring-events`. It must not call `DeviceEvent::create()` inside the socket loop. The event consumer maps source identity to a canonical `Device`, validates canonical ownership, stores bounded evidence, and then creates one `DeviceEvent` so the existing observer remains the sole Control Room bridge.

- [x] **Step 5: Run SNMP, envelope, and signal regressions**

Run: `php artisan test tests/Unit/Monitoring/SnmpV3ProtocolTest.php tests/Feature/Monitoring/SnmpTrapIngestionTest.php tests/Feature/Monitoring/MonitoringDeliveryTest.php tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php`

Expected: all tests pass; fixtures contain no production secrets and one trap produces at most one canonical signal correlation.

Evidence (2026-07-23, uncommitted shared worktree): the required suite began RED because the credential-lease seam did not exist. The implementation now polls one authPriv SNMPv3 root monitor and fans bounded scalar inventory, interface-counter/rate, and sensor observations into canonical child monitors without repeated network walks. One-use expiring leases do not serialise credential material; v1/v2c remains disabled unless a current Site-and-Device compatibility exception records an owner, reason, expiry, and migration status. The native transport requires an egress-authorised numeric target, honours the stricter configured response-byte cap, bounds OIDs/varbinds/values, closes sessions, and clears local material references. Discovery uses mutable fingerprints and serial evidence without treating model-level `sysObjectID` as an immutable device identity.

The supervised trap path accepts at most 65,507 bytes, resolves exactly one active central Site/network scope, authenticates and decrypts SNMPv3 USM/AES traffic, applies replay/timeliness guards, allowlists bounded varbinds, and stages a signed event envelope without writing `DeviceEvent` in the socket loop. The event consumer re-resolves the canonical Device and Site, creates one `DeviceEvent`, and leaves the existing observer as the sole Control Room bridge. Tampered, unauthenticated, oversized, out-of-scope, ambiguous, replayed, and unapproved compatibility traffic fails closed without persisting raw datagrams or credentials. A fresh MySQL run exposed and fixed an overlong generated compatibility foreign-key identifier by using explicit bounded names.

The final connected protocol, poll, trap, delivery, scheduler, and DeviceEvent-to-Control Room matrix passed 58 tests / 417 assertions in 453.31 seconds; the direct-probe, egress, and single-application architecture matrix passed 4 / 3,060. Scoped Pint, PHP syntax, command registration, active partition-vocabulary scan, and `git diff --check` passed. `ext-snmp` is absent from this development PHP runtime, so the native transport correctly fails closed and production activation remains unproven until every SNMP worker or collector host shows the extension and a supervised listener configuration. Step 6, commit, push, deployment, live SNMP fixtures, and desktop acceptance remain open.

- [ ] **Step 6: Commit**

```powershell
git add app/Domain/Monitoring/Contracts/CredentialLeaseProvider.php app/Domain/Monitoring/Data/CredentialLease.php app/Domain/Monitoring/Services/UnavailableCredentialLeaseProvider.php app/Domain/Monitoring/Protocols/Snmp app/Domain/Monitoring/Adapters/SnmpV3ProbeAdapter.php app/Domain/Monitoring/Discovery/Adapters/SnmpInventoryDiscoveryAdapter.php app/Console/Commands/MonitoringListenSnmpTraps.php app/Providers/AppServiceProvider.php tests/Unit/Monitoring/SnmpV3ProtocolTest.php tests/Feature/Monitoring/SnmpTrapIngestionTest.php tests/Fixtures/monitoring/snmp
git commit -m "feat(monitoring): add bounded SNMPv3 collection"
```

## Task 10: Ingest syslog and bounded NetFlow, IPFIX, and sFlow telemetry

**Files:**

- Create: `app/Domain/Monitoring/Protocols/Syslog/SyslogDecoder.php`
- Create: `app/Domain/Monitoring/Protocols/Syslog/SyslogMessage.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowDatagram.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowRecord.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowBinaryReader.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowTemplate.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowTemplateField.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowTemplateRegistry.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowRecordDecoder.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/NetFlowV5Decoder.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/NetFlowV9Decoder.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/IpfixDecoder.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/SflowV5Decoder.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowAggregate.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowSequenceHealth.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowAggregator.php`
- Create: `app/Domain/Monitoring/Protocols/InboundTelemetryScope.php`
- Create: `app/Domain/Monitoring/Protocols/InboundTelemetryScopeResolver.php`
- Create: `app/Domain/Monitoring/Protocols/Syslog/SyslogIntakeService.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowExporterSequenceGuard.php`
- Create: `app/Domain/Monitoring/Protocols/Flow/FlowIntakeService.php`
- Create: `app/Domain/Monitoring/Services/ListenerHeartbeatReporter.php`
- Create: `app/Domain/Monitoring/Services/UdpPeerAddress.php`
- Modify: `app/Domain/Monitoring/Handlers/EventEnvelopeHandler.php`
- Create: `app/Console/Commands/MonitoringListenSyslog.php`
- Create: `app/Console/Commands/MonitoringListenFlow.php`
- Create: `database/migrations/2026_07_21_100005_create_monitoring_flow_exporter_states.php`
- Modify: `app/Domain/Monitoring/Discovery/Services/DiscoveryScopeValidator.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/monitoring.php`
- Modify: `.env.example`
- Create: `tests/Unit/Monitoring/SyslogDecoderTest.php`
- Create: `tests/Unit/Monitoring/FlowDecoderTest.php`
- Create: `tests/Feature/Monitoring/EventListenerBoundaryTest.php`
- Create: `tests/fixtures/monitoring/syslog/` fixture directory
- Create: `tests/fixtures/monitoring/flow/` fixture directory

- [x] **Step 1: Write failing syslog normalisation tests**

```php
it('parses RFC5424 and bounds untrusted content', function () {
    $event = app(SyslogDecoder::class)->decode(file_get_contents(base_path('tests/Fixtures/monitoring/syslog/rfc5424.log')));

    expect($event)->toMatchArray(['facility' => 4, 'severity' => 2, 'hostname' => 'edge-01', 'app' => 'sshd'])
        ->and(strlen($event['message']))->toBeLessThanOrEqual(4096)
        ->and($event)->not->toHaveKeys(['raw_datagram']);
});
```

Cover RFC3164, RFC5424 structured data, invalid UTF-8, newline injection, unknown sender, oversize datagram, timestamp skew, and deduplication.

- [x] **Step 2: Write failing flow fixture tests**

```php
it('decodes each approved flow family into the same aggregate shape', function (string $fixture, string $decoder) {
    $records = app($decoder)->decode(file_get_contents(base_path("tests/Fixtures/monitoring/flow/{$fixture}")));
    expect($records[0])->toMatchArray([
        'source_ip' => '10.44.0.10', 'destination_ip' => '10.44.0.20',
        'source_port' => 51514, 'destination_port' => 443, 'protocol' => 6,
    ])->and($records[0]['bytes'])->toBeGreaterThan(0);
})->with([
    ['netflow-v5.bin', NetFlowV5Decoder::class],
    ['netflow-v9.bin', NetFlowV9Decoder::class],
    ['ipfix.bin', IpfixDecoder::class],
    ['sflow-v5.bin', SflowV5Decoder::class],
]);
```

Cover template-before-data enforcement for NetFlow v9/IPFIX, enterprise field skipping, sFlow sample bounds, packet truncation, unknown versions, exporter boot reset, sequence gap, and maximum records per datagram.

- [x] **Step 3: Run decoder tests and verify RED**

Run: `php artisan test tests/Unit/Monitoring/SyslogDecoderTest.php tests/Unit/Monitoring/FlowDecoderTest.php`

Expected: FAIL because protocol decoders do not exist.

- [x] **Step 4: Implement decoders, aggregation, and listeners**

Syslog emits only allowlisted fields plus a sanitised 4 KiB message and SHA-256 raw hash. Flow decoders use network byte order, reject incomplete structures, cap 1,000 records/datagram, and return the common `FlowDatagram` value. `FlowAggregator` groups site/exporter/interface/direction/protocol/application buckets per minute, publishes signed metric envelopes for the Task 17 storage consumer, and emits a gap health event when exporter sequence is discontinuous.

Both listener commands must resolve the sender through an approved discovery scope, publish signed envelopes to `monitoring-events`, expose heartbeat counters, and avoid inline Eloquent writes. Use distinct supervisor processes and ports; no listener may bind `0.0.0.0` unless the deployment allowlist explicitly includes the receiving network.

- [x] **Step 5: Verify listener boundary and decoders**

Run: `php artisan test tests/Unit/Monitoring/SyslogDecoderTest.php tests/Unit/Monitoring/FlowDecoderTest.php tests/Feature/Monitoring/EventListenerBoundaryTest.php`

Expected: all tests pass; malformed or unknown-source datagrams are counted and dropped without domain writes.

Evidence (2026-07-23): the RED decoder run failed because the syslog and flow protocol classes did not yet exist. The completed implementation accepts RFC5424/RFC3164 and network-byte-order NetFlow v5/v9, IPFIX, and sFlow v5; bounds and sanitises untrusted content; maintains bounded template/exporter sequence state; aggregates per-minute metric buckets; and resolves every sender through exactly one active central Site/network discovery scope. The syslog and flow commands use distinct ports, enforce an explicit bind allowlist, publish only signed `Event` envelopes to `monitoring-events`, and expose heartbeat/counter state without inline `DeviceEvent` writes. The final decoder/listener/SNMP/delivery matrix passed 58 tests / 468 assertions; direct-probe, egress, and single-application architecture passed 4 / 3,172 assertions. Both commands are registered, scoped Pint and syntax checks passed, the active partition-vocabulary scan was clear, sensitive-term hits were only negative assertions proving raw datagrams are absent, and `git diff --check` passed. Live UDP receipt, supervisor configuration, production heartbeat/alerting, and Task 17 external time-series projection remain unproven; no commit, push, deployment, or browser run was performed.

- [ ] **Step 6: Commit**

```powershell
git add app/Domain/Monitoring/Protocols/Syslog app/Domain/Monitoring/Protocols/Flow app/Console/Commands/MonitoringListenSyslog.php app/Console/Commands/MonitoringListenFlow.php tests/Unit/Monitoring/SyslogDecoderTest.php tests/Unit/Monitoring/FlowDecoderTest.php tests/Feature/Monitoring/EventListenerBoundaryTest.php tests/Fixtures/monitoring/syslog tests/Fixtures/monitoring/flow
git commit -m "feat(monitoring): ingest syslog and flow telemetry"
```

## Task 11: Add approved read-only SSH and WinRM inventory collection

**Files:**

- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/InventoryQuery.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/InventoryResult.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/SshInventoryTransport.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/WinRmInventoryTransport.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/SshConnection.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/SshConnectionFactory.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/NativeSshConnectionFactory.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/PhpseclibSshConnection.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/SshCommandResponse.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/WinRmHttpClient.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/NativeWinRmHttpClient.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/WinRmHttpResponse.php`
- Create: `app/Domain/Monitoring/Protocols/RemoteInventory/WinRmTransportException.php`
- Create: `app/Domain/Monitoring/Adapters/SshInventoryProbeAdapter.php`
- Create: `app/Domain/Monitoring/Adapters/WinRmInventoryProbeAdapter.php`
- Create: `config/monitoring-inventory.php`
- Modify: `app/Domain/Monitoring/Enums/MonitorKind.php`
- Modify: `app/Domain/Monitoring/Data/ProbeTarget.php`
- Modify: `app/Domain/Monitoring/Data/AuthorizedProbeTarget.php`
- Modify: `app/Domain/Monitoring/Services/ProbeAdapterRegistry.php`
- Modify: `app/Domain/Monitoring/Services/MonitorCheckRunner.php`
- Modify: `app/Domain/Monitoring/Services/MonitorScheduler.php`
- Modify: `app/Domain/Monitoring/Jobs/RunMonitorCheck.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Unit/Monitoring/RemoteInventoryProtocolTest.php`
- Create: `tests/Feature/Monitoring/RemoteInventoryMonitorCheckTest.php`
- Modify: `tests/Unit/Monitoring/MonitoringRuntimeConfigurationTest.php`
- Modify: `tests/Feature/Monitoring/RunMonitorCheckTest.php`

- [x] **Step 1: Write failing allowlist and non-command tests**

```php
it('runs only an approved read-only inventory query', function () {
    $query = InventoryQuery::fromProfile('linux.basic');
    expect($query->commands)->toBe([
        ['uname', '-sr'],
        ['uptime', '-s'],
        ['df', '-P', '-B1'],
        ['systemctl', 'list-units', '--type=service', '--state=failed', '--no-legend'],
    ]);
    expect(fn () => InventoryQuery::fromArbitraryCommand('reboot'))
        ->toThrow(LogicException::class, 'Arbitrary remote commands are forbidden.');
});
```

Add host-key mismatch, expired credential lease, output cap, timeout, sudo request, shell metacharacter, WinRM HTTP denial, WinRM certificate mismatch, SOAP size, partial query, and redaction cases.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Unit/Monitoring/RemoteInventoryProtocolTest.php`

Expected: FAIL because approved inventory profiles and transports do not exist.

- [x] **Step 3: Implement fixed inventory profiles and hardened transports**

`config/monitoring-inventory.php` must define named profiles only. Linux profiles are arrays of executable plus arguments; Windows profiles are fixed CIM queries such as `Win32_OperatingSystem`, `Win32_LogicalDisk`, and `Win32_Service` with selected properties. No request or database field may contain an executable command.

SSH uses phpseclib, requires a pinned host-key fingerprint, disables agent forwarding, PTY, port forwarding, and sudo, enforces 15-second/query and 1 MiB/output caps, and discards lease material. WinRM requires HTTPS with peer/hostname verification, Kerberos or certificate authentication supplied by the ephemeral lease, disables redirects, permits only configured CIM resource URIs/actions, and caps SOAP responses.

Both adapters output inventory/performance observations. They never call `CommandDispatchPort` and cannot mutate remote state.

- [x] **Step 4: Run the remote inventory test**

Run: `php artisan test tests/Unit/Monitoring/RemoteInventoryProtocolTest.php tests/Unit/Monitoring/MonitoringRuntimeConfigurationTest.php`

Expected: tests pass and command dispatch remains the rejecting implementation.

Evidence (2026-07-23): the RED run stopped because the SSH connection contract did not exist. `linux.basic` now exposes only fixed `uname`, `uptime`, `df`, and failed-service operations; `windows.basic` exposes only selected `Win32_OperatingSystem`, `Win32_LogicalDisk`, and `Win32_Service` properties. SSH uses phpseclib against an egress-authorised numeric address, verifies the pinned SHA-256 host key before consuming the one-use lease, never enables an agent, PTY, forwarding, or sudo, and applies 15-second/query and 1 MiB/output bounds. WinRM accepts only the HTTPS `/wsman` target, pins cURL resolution while preserving hostname verification, disables redirects/proxies, supports Kerberos or in-memory client-certificate authentication, restricts SOAP to fixed WQL/resource/action values, and follows at most 16 bounded WS-Man `Pull` pages. Both transports retain only scalar normalised facts and feed the existing scheduler, observation, `DeviceEvent`, Control Room, and IT lifecycle; they have no domain-write or command-dispatch access. The final protocol/configuration/integration/direct-adapter/scheduler/architecture matrix passed 65 tests / 3,543 assertions. phpseclib, DOM, pinned cURL resolution, Kerberos, and certificate-blob runtime capabilities were present; scoped Pint, syntax, production mutation/domain-write/partition scans, and `git diff --check` passed. No real SSH/WinRM host, credential broker, production worker, deployment, or browser was exercised, so live authentication/host compatibility remains open; no commit or push was performed.

- [ ] **Step 5: Commit**

```powershell
git add app/Domain/Monitoring/Protocols/RemoteInventory app/Domain/Monitoring/Adapters/SshInventoryProbeAdapter.php app/Domain/Monitoring/Adapters/WinRmInventoryProbeAdapter.php config/monitoring-inventory.php app/Domain/Monitoring/Enums/MonitorKind.php tests/Unit/Monitoring/RemoteInventoryProtocolTest.php
git commit -m "feat(monitoring): add approved remote inventory checks"
```

## Task 12: Replace the provider-shaped runtime surface with narrow capability contracts

**Files:**

- Create: `app/Services/Integration/Contracts/ConnectionHealthCapability.php`
- Create: `app/Services/Integration/Contracts/InventoryDiscoveryCapability.php`
- Create: `app/Services/Integration/Contracts/DeviceSyncCapability.php`
- Create: `app/Services/Integration/Contracts/ObservationCollectionCapability.php`
- Create: `app/Services/Integration/Contracts/EventCollectionCapability.php`
- Create: `app/Services/Integration/Contracts/WebhookVerificationCapability.php`
- Create: `app/Services/Integration/Contracts/TopologyCollectionCapability.php`
- Create: `app/Services/Integration/Contracts/SnapshotCollectionCapability.php`
- Create: `app/Services/Integration/Data/IntegrationCapabilityManifest.php`
- Create: `app/Services/Integration/Data/ProviderObservationPage.php`
- Create: `app/Services/Integration/Data/ProviderTopologyPage.php`
- Create: `app/Services/Integration/Data/ProviderEventPage.php`
- Create: `app/Services/Integration/Data/ProviderSnapshotPage.php`
- Create: `app/Services/Integration/Data/ProviderPageGuard.php`
- Create: `app/Services/Integration/Data/ProviderWebhookRequest.php`
- Create: `app/Services/Integration/Data/VerifiedProviderEvent.php`
- Create: `app/Services/Integration/Exceptions/CapabilityUnavailable.php`
- Create: `app/Services/Integration/Exceptions/WebhookRejected.php`
- Create: `app/Services/Integration/ProviderEventProjector.php`
- Modify: `app/Services/Integration/IntegrationAdapterRegistry.php`
- Modify: `app/Services/Integration/Adapters/UnifiAdapter.php`
- Modify: `app/Services/Integration/Adapters/MilesightAdapter.php`
- Modify: `app/Services/Integration/Adapters/QueclinkAdapter.php`
- Modify: `app/Jobs/Integration/SyncIntegrationDevicesJob.php`
- Modify: `app/Jobs/Integration/PullIntegrationHealthJob.php`
- Modify: `app/Http/Controllers/Api/WebhookReceiverController.php`
- Modify: `app/Domain/Monitoring/Handlers/EventEnvelopeHandler.php`
- Create: `app/Domain/Monitoring/Jobs/PullProviderCapability.php`
- Create: `app/Domain/Monitoring/Models/ProviderCapabilityCursor.php`
- Create: `app/Domain/Monitoring/Models/ProviderCapabilityException.php`
- Create: `config/integration-capabilities.php`
- Create: `database/migrations/2026_07_21_100006_create_monitoring_provider_runtime_tables.php`
- Create: `tests/Unit/Integration/TypedProviderCapabilityTest.php`
- Create: `tests/Feature/Integrations/ProviderCapabilityMigrationTest.php`
- Modify: `tests/Feature/Integrations/WebhookReceiverTest.php`

- [x] **Step 1: Write failing capability declaration tests**

```php
it('declares capabilities by interface rather than a permissive array', function (string $provider, array $expected) {
    $adapter = app(IntegrationAdapterRegistry::class)->resolve($provider);
    $manifest = app(IntegrationAdapterRegistry::class)->manifest($provider);

    expect($manifest->provider)->toBe($provider)
        ->and($manifest->capabilities)->toBe($expected)
        ->and($manifest->version)->toMatch('/^\d+\.\d+$/');
    foreach ($expected as $contract) {
        expect($adapter)->toBeInstanceOf($contract);
    }
})->with([
    // Populate each expected list only after the corresponding method is
    // substantive and its provider fixture proves pagination/error behavior.
    // At plan start, UniFi has working connection/inventory/device-sync paths;
    // Milesight and the cloud Queclink adapter have connection testing only.
    'unifi' => ['unifi', [ConnectionHealthCapability::class, InventoryDiscoveryCapability::class, DeviceSyncCapability::class]],
    'milesight' => ['milesight', [ConnectionHealthCapability::class]],
    'queclink' => ['queclink', [ConnectionHealthCapability::class]],
]);
```

Add tests for limits, permissions, sensitivity, safe polling bounds, pagination cursor, rate-limit delay, partial response, retry, backfill checkpoint, and an absent capability returning 404/hidden UI rather than a runtime method error.

- [x] **Step 2: Run capability tests and verify RED**

Run: `php artisan test tests/Unit/Integration/TypedProviderCapabilityTest.php tests/Feature/Integrations/ProviderCapabilityMigrationTest.php`

Expected: FAIL because the narrow contracts and typed manifests do not exist.

- [x] **Step 3: Define the narrow contracts**

Each interface has one responsibility. For example:

```php
interface ObservationCollectionCapability
{
    public function observationCursor(IntegrationSiteConfig $site): ?string;
    public function collectObservations(IntegrationSiteConfig $site, ?string $cursor, int $limit): ProviderObservationPage;
}

interface TopologyCollectionCapability
{
    public function collectTopology(IntegrationSiteConfig $site, ?string $cursor, int $limit): ProviderTopologyPage;
}
```

`WebhookVerificationCapability` verifies signature, timestamp skew, replay nonce, provider/site identity, and body-size limit before returning a normalised event envelope. `IntegrationCapabilityManifest` contains provider, adapter version, implemented contract class names, required permission codes, sensitivity labels, page limit, minimum interval seconds, and backfill limit. Do not add monitoring methods to `IntegrationAdapterInterface`; keep it as a compatibility facade until every existing controller/job calls a narrow capability.

- [x] **Step 4: Migrate the three adapters and provider jobs**

Move existing substantive method bodies behind implemented capability methods without changing provider IDs or canonical device writes. Never advertise an interface for an existing empty array or deferred `SyncResult`: Milesight and cloud Queclink capabilities remain absent until this task contains a real provider fixture, bounded pagination/error behavior, and canonical write path for that capability. The existing direct Queclink TCP intake remains separately described and must not be relabelled as cloud capability proof. Jobs must request the specific capability from `IntegrationAdapterRegistry::capability($provider, Contract::class)`, run on `monitoring-provider`, persist a per-site/capability cursor, honour provider retry headers, and publish normalised signed envelopes. A partial page advances only the last safely persisted cursor and records missing/invalid items as bounded integration exceptions. `WebhookReceiverController` must request `WebhookVerificationCapability`, reject invalid/expired/replayed payloads before persistence, and publish the verified event to `monitoring-events` rather than creating a competing signal path.

- [x] **Step 5: Run existing integration compatibility suites**

Run: `php artisan test tests/Unit/Integration/TypedProviderCapabilityTest.php tests/Feature/Integrations tests/Feature/Integrations/WebhookReceiverTest.php tests/Feature/SecurityDevices/IntegrationsHubTest.php tests/Feature/SecurityDevices/CanonicalIntegrationEventHistoryTest.php tests/Feature/Sites/SiteIntegrationReadBoundaryTest.php tests/Feature/Sites/SiteIntegrationMutationSafetyTest.php`

Expected: all tests pass; UniFi, Milesight, and Queclink retain current setup/sync behavior while runtime work uses typed capabilities.

Evidence (2026-07-23, uncommitted shared worktree): the capability tests began RED because the narrow contracts and typed manifest did not exist. The registry now fails closed and advertises only implemented contracts: UniFi exposes connection health, inventory discovery, device sync, and verified webhooks; Milesight and cloud Queclink expose connection health only. Page objects bound cursors, retries, partial exceptions, backfill, topology evidence, and sensitive field names. Provider polling runs on `monitoring-provider`, uses one exact active Site mapping and provider connection, publishes signed observation envelopes, advances only the last safely persisted cursor, records bounded exceptions, and creates no runtime state for an absent capability. The legacy health scheduler now only dispatches declared observation capabilities and contains no direct canonical-device write path.

UniFi webhook intake now requires the integration key, HMAC signature, bounded timestamp skew, nonce, replay protection, body cap, and mapped external provider Site identity before it publishes a signed event. Production replay protection fails closed without the configured shared store; the local store override is test-only. Projection revalidates the canonical Site mapping, preserves provider/source idempotency across the application, stores normalised bounded evidence with a hash and no raw payload, then invokes the existing alert route. The required compatibility and architecture matrix passed 82 tests / 1,388 assertions; the focused scheduler regression passed 11 / 65; and the post-format affected suite passed 55 / 347. Scoped Pint passed after fixing eight touched files, 27 Task 12 PHP files passed syntax checks, the single-application vocabulary and runtime-facade/TLS-bypass scans were clear, and scoped `git diff --check` passed. No real provider credentials or live APIs, production Redis replay store, queue supervisor, deployment, or browser were exercised. Observation, event-polling, topology, and snapshot capabilities remain absent unless a provider has a substantive bounded implementation; no commit, push, or deployment was performed.

- [ ] **Step 6: Commit**

```powershell
git add app/Services/Integration/Contracts app/Services/Integration/Data/IntegrationCapabilityManifest.php app/Services/Integration/Data/ProviderObservationPage.php app/Services/Integration/Data/ProviderTopologyPage.php app/Services/Integration/IntegrationAdapterRegistry.php app/Services/Integration/Adapters/UnifiAdapter.php app/Services/Integration/Adapters/MilesightAdapter.php app/Services/Integration/Adapters/QueclinkAdapter.php app/Jobs/Integration/SyncIntegrationDevicesJob.php app/Jobs/Integration/PullIntegrationHealthJob.php app/Http/Controllers/Api/WebhookReceiverController.php app/Domain/Monitoring/Jobs/PullProviderCapability.php tests/Unit/Integration/TypedProviderCapabilityTest.php tests/Feature/Integrations/ProviderCapabilityMigrationTest.php
git commit -m "refactor(integrations): add typed monitoring capabilities"
```

## Task 13: Persist topology snapshots and evidence-bearing edges

**Files:**

- Create: `database/migrations/2026_07_21_100003_create_monitoring_topology_tables.php`
- Create: `app/Domain/Monitoring/Topology/Models/TopologySnapshot.php`
- Create: `app/Domain/Monitoring/Topology/Models/TopologyNode.php`
- Create: `app/Domain/Monitoring/Topology/Models/TopologyEdge.php`
- Create: `app/Domain/Monitoring/Topology/Models/TopologyChange.php`
- Create: `app/Domain/Monitoring/Topology/Data/TopologyEvidence.php`
- Create: `app/Domain/Monitoring/Topology/Database/ImmutableTopologyBuilder.php`
- Create: `app/Domain/Monitoring/Topology/Database/TopologySnapshotQueryBuilder.php`
- Create: `app/Domain/Monitoring/Topology/Exceptions/ProviderTopologyDeferred.php`
- Create: `app/Domain/Monitoring/Topology/Services/ProviderTopologyCollector.php`
- Create: `app/Domain/Monitoring/Topology/Services/TopologySnapshotBuilder.php`
- Create: `app/Domain/Monitoring/Topology/Services/TopologyDiffService.php`
- Create: `app/Domain/Monitoring/Jobs/BuildTopologySnapshot.php`
- Create: `app/Domain/Monitoring/Handlers/TopologyProjectionEnvelopeHandler.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `database/factories/TopologySnapshotFactory.php`
- Create: `tests/Feature/Monitoring/TopologySnapshotTest.php`

- [x] **Step 1: Write failing snapshot, confidence, and diff tests**

```php
it('keeps inferred topology temporal and does not overwrite canonical relationships', function () {
    $site = Site::factory()->create();
    [$switch, $accessPoint] = Device::factory()->count(2)->create();
    foreach ([$switch, $accessPoint] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);
    }
    $snapshot = app(TopologySnapshotBuilder::class)->build($site, [
        new TopologyEvidence('lldp', $switch->id, $accessPoint->id, 'ethernet', 'Gi1/0/8', 'eth0', 0.95, ['chassis_hash' => 'abc']),
        new TopologyEvidence('arp', $switch->id, $accessPoint->id, 'observed_path', null, null, 0.45, ['table_age_seconds' => 12]),
    ]);

    expect($snapshot->edges)->toHaveCount(2)
        ->and($snapshot->edges->firstWhere('source', 'lldp')->confidence)->toBe('0.9500')
        ->and(DeviceRelationship::count())->toBe(0);
});
```

Add LLDP, CDP, ARP, forwarding table, routes, provider evidence, conflicting edges, unresolved nodes, removed/added/changed edge diff, cross-site and canonical-ownership rejection, immutable completed snapshot, and deduplication tests.

- [x] **Step 2: Run topology tests and verify RED**

Run: `php artisan test tests/Feature/Monitoring/TopologySnapshotTest.php`

Expected: FAIL because topology persistence and builders do not exist.

- [x] **Step 3: Create temporal topology persistence**

`monitoring_topology_snapshots` stores site, source run/envelope, captured/completed times, status, node/edge counts, and summary. Nodes reference canonical `device_id` when matched and otherwise a discovery candidate plus hashed observed identity. Edges store source, kind, endpoint node IDs, local/remote port, confidence decimal, bounded evidence, first/last seen, and stable edge hash. Changes link previous/current snapshots and store `added`, `removed`, or `changed` plus evidence.

Completed snapshots and edges are immutable. Existing `device_relationships` remain the reviewed operational relationships; inferred edges never overwrite them silently.

- [x] **Step 4: Build and diff snapshots on the topology queue**

`BuildTopologySnapshot` consumes SNMP LLDP/CDP/ARP/forwarding/route evidence plus provider `TopologyCollectionCapability` pages, resolves identities through `DeviceIdentityMatcher`, creates one snapshot, computes changes against the latest completed site snapshot, and publishes a topology projection envelope. Set `$connection = 'redis'`, `$queue = 'monitoring-topology'`, timeout 300 seconds, and one unique job per site/source checkpoint.

- [x] **Step 5: Run topology and provider tests**

Run: `php artisan test tests/Feature/Monitoring/TopologySnapshotTest.php tests/Feature/Integrations/ProviderCapabilityMigrationTest.php`

Expected: all tests pass and topology counts reconcile to stored nodes/edges/changes.

Evidence (2026-07-23, uncommitted shared worktree): the topology test began RED at the absent snapshot schema. The completed implementation stores immutable Site-scoped snapshots, canonical or explicitly unresolved hashed nodes, stable evidence-bearing edges, and immutable added/removed/changed records. Stable edge identity is separate from content identity, so port, confidence, or evidence changes are reported as `changed` rather than misleading remove/add pairs. LLDP, CDP, ARP, forwarding-table, route, and provider evidence are bounded; identical evidence is deduplicated, conflicting port claims remain visible in the summary, and cross-Site Device or discovery-candidate endpoints are rejected. No inferred edge writes to the reviewed `device_relationships` table.

`BuildTopologySnapshot` is unique per Site/source/checkpoint, uses Redis on `monitoring-topology`, has a 300-second timeout, accepts only normalised native evidence, and publishes an idempotent signed projection verified against the completed canonical snapshot. Provider collection requires the typed `TopologyCollectionCapability`, exact active Site mapping and application provider connection, declared page/backfill limits, advancing cursors, complete pages, and canonical identity matching through the Site discovery scope. The final topology/provider/typed-capability/single-application matrix passed 33 tests / 882 assertions; the focused immutability/queue/provider continuation passed 4 / 39. Scoped Pint passed, 16 Task 13 PHP files passed syntax checks, single-application and sensitive-persistence scans were clear, and scoped `git diff --check` passed. No production adapter currently advertises topology, the native SNMP runtime does not yet emit its LLDP/CDP/ARP/forwarding/route pages, and no real provider API, Redis worker, deployment, or browser was exercised; those live producer and operations gaps remain explicit. No commit or push was performed.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_21_100003_create_monitoring_topology_tables.php app/Domain/Monitoring/Topology app/Domain/Monitoring/Jobs/BuildTopologySnapshot.php database/factories/TopologySnapshotFactory.php tests/Feature/Monitoring/TopologySnapshotTest.php
git commit -m "feat(monitoring): add temporal topology snapshots"
```

## Task 14: Apply dependencies, maintenance, hysteresis, coverage, and honest roll-ups

**Files:**

- Create: `database/migrations/2026_07_21_100004_add_monitoring_policy_and_dependency_records.php`
- Create: `app/Domain/Monitoring/Models/MonitorDependency.php`
- Create: `app/Domain/Monitoring/Models/MonitoringMaintenanceWindow.php`
- Create: `app/Domain/Monitoring/Models/MonitoringCoverageExpectation.php`
- Create: `app/Domain/Monitoring/Services/DependencyEvaluator.php`
- Create: `app/Domain/Monitoring/Services/MaintenanceEvaluator.php`
- Create: `app/Domain/Monitoring/Services/MonitorStateMachine.php`
- Create: `app/Domain/Monitoring/Services/MonitoringRollupService.php`
- Create: `app/Domain/Monitoring/Services/CoverageAnalyzer.php`
- Create: `app/Domain/Monitoring/Data/MonitorTransitionDecision.php`
- Create: `app/Domain/Monitoring/Data/DependencyDecision.php`
- Create: `app/Domain/Monitoring/Data/CoverageResult.php`
- Modify: `app/Domain/Monitoring/Services/MonitoringObservationIngestor.php`
- Modify: `app/Domain/Monitoring/Models/MonitoringProfile.php`
- Modify: `app/Observers/DeviceEventObserver.php`
- Modify: `app/Services/ControlRoom/SignalProcessingService.php`
- Modify: `app/Listeners/It/CreateOrUpdateMonitoringTicket.php`
- Create: `tests/Feature/Monitoring/MonitoringPolicyTest.php`
- Create: `tests/Feature/Monitoring/DependencySuppressionTest.php`

- [x] **Step 1: Write failing monitoring-semantics tests**

```php
it('shows downstream symptoms as suppressed while emitting one root cause', function () {
    $site = Site::factory()->create();
    [$wanDevice, $cameraDevice] = Device::factory()->count(2)->create();
    foreach ([$wanDevice, $cameraDevice] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);
    }
    $wan = Monitor::factory()->failed()->create(['device_id' => $wanDevice->id, 'affects_availability' => true]);
    $camera = Monitor::factory()->failed()->create(['device_id' => $cameraDevice->id, 'affects_availability' => true]);
    MonitorDependency::create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $wan->id,
        'downstream_monitor_id' => $camera->id,
        'policy' => 'suppress_notifications_and_ticketing',
    ]);

    $result = app(DependencyEvaluator::class)->evaluate($camera, now());
    expect($result->effectiveState)->toBe(MonitorState::Suppressed)
        ->and($result->rootCauseMonitorId)->toBe($wan->id)
        ->and($result->symptomVisible)->toBeTrue();
});
```

Add dependency cycle rejection, topology-inferred dependency confidence threshold, active maintenance, maintenance end, consecutive failure, recovery confirmation, duration threshold, rising/falling hysteresis, stale/unknown non-improvement, not-applicable exclusion, explainable baseline bound, missing/unsupported/paused/collection-failed coverage, and device/site/estate roll-up tests. Add a regression with two simultaneous root-cause monitors on one device: recovering one monitor may resolve only its matching Control Room correlation and mark only its matching IT incident as monitoring-recovered.

- [x] **Step 2: Run policy tests and verify RED**

Run: `php artisan test tests/Feature/Monitoring/MonitoringPolicyTest.php tests/Feature/Monitoring/DependencySuppressionTest.php`

Expected: FAIL because policy records and evaluators do not exist.

- [x] **Step 3: Add explicit policy fields and evaluators**

Extend monitoring profiles with failure/recovery duration, rising/falling thresholds, baseline window/minimum samples/deviation multiplier, maintenance policy, roll-up policy, and retention policy ID. Add site- and ownership-safe dependencies, recurring/one-off maintenance windows, and device-class capability expectations.

Refactor the current confirmation logic out of `MonitoringObservationIngestor` into `MonitorStateMachine`. The ingestor persists every observation, asks the state machine for the reported transition, asks maintenance/dependency evaluators for the effective transition, and creates a `DeviceEvent` only for a confirmed unsuppressed root-cause transition. Suppressed symptoms persist their own history and root-cause reference.

Every failure and recovery event must carry the same immutable `monitor_correlation_key` derived from canonical device, root-cause monitor, condition, and site context. `DeviceEventObserver` projects that key into normalised signal data. `SignalProcessingService::processDeviceRecovery()` matches unresolved alerts by that key in addition to canonical device identity. `CreateOrUpdateMonitoringTicket::handleRecovery()` follows the matching source alert/correlation link and must not select every open system incident linked to the device. Legacy device-only recovery is retained only for explicitly identified legacy events and has its own regression.

- [x] **Step 4: Implement deterministic roll-up and coverage rules**

Use this precedence for enabled applicable checks: `failed > degraded > stale > unknown > pending > healthy`; `suppressed` preserves its underlying severity but cannot create a separate root-cause signal; `not_applicable` is excluded. A roll-up with only stale/unknown checks is never healthy. Coverage reports expected checks as `covered`, `missing`, `unsupported`, `paused`, or `collection_failed`, with the capability/evidence that produced that classification.

- [x] **Step 5: Run policy and lifecycle regression suites**

Run: `php artisan test tests/Feature/Monitoring/MonitoringPolicyTest.php tests/Feature/Monitoring/DependencySuppressionTest.php tests/Feature/Monitoring/MonitoringObservationIngestorTest.php tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php tests/Feature/It/ItMonitoringTicketIntegrationTest.php`

Expected: all tests pass; downstream repeats enrich evidence but do not create duplicate Control Room alerts or IT incidents.

Evidence (2026-07-23, uncommitted shared worktree): the policy suite began RED at the absent profile and monitor policy columns. The completed implementation stores explicit confirmation-duration, fixed hysteresis, baseline, maintenance, roll-up, retention, dependency, and coverage policy. `MonitorStateMachine` keeps raw observations immutable while separating reported confirmation from effective state; stale or unknown evidence cannot improve a confirmed failure. One-off, daily, and weekly maintenance suppress notification and ticket creation without hiding the failed symptom, and the first still-failed observation after maintenance emits the root failure. Manual, provider, and topology dependencies are confined to one canonical Site, reject cycles, apply the configured topology-confidence floor, retain downstream evidence, and identify one root cause.

Coverage now distinguishes covered, missing, unsupported, paused, and collection-failed capabilities with bounded reason evidence. Device, Site, and estate roll-ups use `failed > degraded > stale > unknown > pending > healthy`, exclude disabled and not-applicable checks, and preserve the underlying severity of suppressed symptoms. Every native availability failure and recovery carries one SHA-256 `monitor_correlation_key` derived from canonical Site, Device, root monitor, and condition. The Device Event bridge also uses its immutable event ID for signal idempotency, preventing simultaneous monitor roots on one Device from collapsing in the same minute. Control Room and IT recovery match the exact key; device-only recovery is possible only when the incoming legacy event explicitly declares that compatibility path.

The required policy/lifecycle suite plus the broader Control Room regression passed 48 tests / 242 assertions. Security & Devices operations, Device Event projection, single-application access, provider connections, and monitoring architecture passed 28 / 3,664. Scoped Pint, PHP syntax, single-application vocabulary, sensitive-field, and diff checks passed. No real provider or Site endpoint, production Redis worker, deployment, or desktop browser was exercised. Collector outage freshness, external time-series retention, production supervision, load/restore, and runtime-backed desktop acceptance remain in Tasks 15-19. No commit, push, or deployment was performed.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_21_100004_add_monitoring_policy_and_dependency_records.php app/Domain/Monitoring/Models/MonitorDependency.php app/Domain/Monitoring/Models/MonitoringMaintenanceWindow.php app/Domain/Monitoring/Models/MonitoringCoverageExpectation.php app/Domain/Monitoring/Data/MonitorTransitionDecision.php app/Domain/Monitoring/Data/DependencyDecision.php app/Domain/Monitoring/Data/CoverageResult.php app/Domain/Monitoring/Services/DependencyEvaluator.php app/Domain/Monitoring/Services/MaintenanceEvaluator.php app/Domain/Monitoring/Services/MonitorStateMachine.php app/Domain/Monitoring/Services/MonitoringRollupService.php app/Domain/Monitoring/Services/CoverageAnalyzer.php app/Domain/Monitoring/Services/MonitoringObservationIngestor.php app/Domain/Monitoring/Models/MonitoringProfile.php app/Observers/DeviceEventObserver.php app/Services/ControlRoom/SignalProcessingService.php app/Listeners/It/CreateOrUpdateMonitoringTicket.php tests/Feature/Monitoring/MonitoringPolicyTest.php tests/Feature/Monitoring/DependencySuppressionTest.php
git commit -m "feat(monitoring): apply dependencies and health policy"
```

## Task 15: Build the database-free PHP remote collector

**Files:**

- Create: `collector/composer.json`
- Create: `collector/bin/oblivion-collector`
- Create: `collector/src/CollectorApplication.php`
- Create: `collector/src/Config/SignedConfigLoader.php`
- Create: `collector/src/Contracts/CentralApi.php`
- Create: `collector/src/Http/HttpsCentralApi.php`
- Create: `collector/src/Security/EnvelopeVerifier.php`
- Create: `collector/src/Security/ScopeGuard.php`
- Create: `collector/src/Spool/EncryptedSpool.php`
- Create: `collector/src/Spool/CheckpointFile.php`
- Create: `collector/src/Runtime/ProbeRunner.php`
- Create: `collector/src/Runtime/HeartbeatReporter.php`
- Create: `collector/tests/CollectorBoundaryTest.php`
- Create: `collector/tests/EncryptedSpoolTest.php`
- Create: `collector/README.md`

- [x] **Step 1: Write failing no-database, signature, scope, and spool tests**

Create `collector/composer.json` first so the isolated test runner can boot:

```json
{
  "name": "oblivionfindings/monitoring-collector",
  "type": "project",
  "require": {
    "php": "^8.4",
    "ext-curl": "*",
    "ext-json": "*",
    "ext-sodium": "*",
    "symfony/process": "^7.3"
  },
  "require-dev": {"pestphp/pest": "^4.1"},
  "autoload": {"psr-4": {"Oblivion\\Collector\\": "src/"}},
  "autoload-dev": {"psr-4": {"Oblivion\\Collector\\Tests\\": "tests/"}}
}
```

```php
it('contains no database client and executes only signed scoped checks', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($composer['require']))->not->toContain('laravel/framework', 'doctrine/dbal', 'ext-pdo', 'ext-mysqli');

    $config = signedCollectorConfig(siteId: 9, cidrs: ['10.44.0.0/24'], sequence: 4);
    $loader = collectorConfigLoader(publicKeyFixture());
    expect($loader->load($config)->sequence)->toBe(4);
    expect(fn () => $loader->load(tamper($config)))->toThrow(SignatureException::class);
});
```

Add wrong collector ID, wrong site/network/device scope, expired config, rollback sequence, out-of-scope target, forbidden protocol, expired credential lease, encrypted-at-rest assertion, spool maximum, restart checkpoint, duplicate item, ordered flush, acknowledgement, corrupted frame quarantine, and revoked collector tests.

- [x] **Step 2: Run collector tests and verify RED**

Run:

```powershell
Push-Location collector
composer install
vendor/bin/pest
Pop-Location
```

Expected: FAIL because the collector package and classes do not exist.

- [x] **Step 3: Create the standalone PHP 8.4 application source**

The executable supports `enrol`, `run`, `doctor`, and `version`. It accepts central URL, collector UUID, public-key pin, private state directory, and one-time enrolment token via environment/file descriptor. It has no database DSN option.

- [x] **Step 4: Implement signed configuration, scope guard, and encrypted spool**

Verify Ed25519 configuration signatures with the pinned central public key, require monotonically increasing config sequence and expiry, and reject entries outside the collector's approved site/network/device scope. `EncryptedSpool` uses length-prefixed frames encrypted with `sodium_crypto_secretstream_xchacha20poly1305`, fsyncs before acknowledging local receipt, caps bytes/items/age, preserves source sequence, and deletes acknowledged frames only after an atomic checkpoint-file replacement. When the cap is reached, stop new scheduled checks, continue heartbeat/control traffic, and report `buffer_full`; never discard old items silently.

- [x] **Step 5: Implement collector probe execution without commands**

Port the Task 5 probe contracts into dependency-free collector classes for ICMP/TCP/DNS/HTTP/TLS and support SNMP/SSH/WinRM only when an unexpired scoped lease is included in the signed configuration. `ScopeGuard` validates the target immediately before each connection. There is no device-command opcode, arbitrary executable field, shell field, or command dispatch implementation.

- [x] **Step 6: Run collector package tests**

Run:

```powershell
Push-Location collector
composer validate --strict
vendor/bin/pest
php bin/oblivion-collector doctor --config=tests/Fixtures/collector.json
Pop-Location
```

Expected: validation and tests pass; doctor reports `database: absent`, `signature: valid`, `scope: valid`, and `spool: writable`.

**Task 15 verification evidence (2026-07-23, uncommitted shared worktree):** The isolated PHP 8.4 package began RED at 14 failures / 2 passes because the collector runtime classes did not exist. It now provides `enrol`, `run`, `doctor`, and `version`; pinned Ed25519 configuration verification; exact collector/Site/network/device/protocol scope; monotonic configuration checkpoints; connection-time expiry and credential-lease checks; fixed ICMP/TCP/DNS/HTTP/TLS/SNMP/SSH/WinRM probes; signed HTTPS control traffic; and an fsynced, bounded, secretstream-encrypted spool with duplicate protection, corruption quarantine, ordered-prefix acknowledgements, atomic checkpoints, and no silent loss at capacity. The final isolated suite passed 20 tests / 67 assertions; strict Composer validation, the exact doctor command, 20-file PHP syntax, scoped Pint, runtime/dependency/direct-shell boundary scans, and diff checks passed. The connected central configuration and single-application architecture gate passed 12 tests / 770 assertions. The collector has no Laravel, application database, arbitrary shell, device-command, or secondary ownership-partition dependency. Central enrolment/sync endpoints, certificate issuance, revocation, ordered central ingestion, and collector-outage correlation remain Task 16 contracts rather than a live path. `ext-snmp` is absent locally, SSH/WinRM were not exercised against real hosts, and no remote Site, credential broker, supervised service, deployment, browser, commit, or push was exercised.

- [ ] **Step 7: Commit**

```powershell
git add collector
git commit -m "feat(monitoring): add database-free remote collector"
```

## Task 16: Enrol, scope, revoke, and recover collectors in order

**Files:**

- Create: `database/migrations/2026_07_21_100005_extend_monitoring_collectors_for_runtime.php`
- Modify: `app/Domain/Monitoring/Models/MonitoringCollector.php`
- Create: `app/Domain/Monitoring/Models/CollectorEnrollment.php`
- Create: `app/Domain/Monitoring/Models/CollectorCheckpoint.php`
- Create: `app/Domain/Monitoring/Services/CollectorEnrollmentService.php`
- Create: `app/Domain/Monitoring/Services/CollectorConfigurationService.php`
- Create: `app/Domain/Monitoring/Services/CollectorIngestService.php`
- Create: `app/Domain/Monitoring/Services/CollectorHealthService.php`
- Create: `app/Domain/Monitoring/Services/CollectorTransportAuthenticator.php`
- Create: `app/Domain/Monitoring/Jobs/EvaluateCollectorHealth.php`
- Create: `app/Http/Middleware/AuthenticateMonitoringCollector.php`
- Create: `app/Http/Controllers/Api/Monitoring/CollectorEnrollmentController.php`
- Create: `app/Http/Controllers/Api/Monitoring/CollectorSyncController.php`
- Create: `routes/monitoring-collector.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Feature/Monitoring/CollectorLifecycleTest.php`
- Create: `tests/Feature/Monitoring/CollectorOutageCorrelationTest.php`

- [x] **Step 1: Write failing enrolment, scoping, order, and revocation tests**

```php
it('enrols once and returns only the collector site scope', function () {
    $enrollment = app(CollectorEnrollmentService::class)->issue(siteId: 9, actorId: 7);
    $response = $this->postJson('/api/monitoring/collectors/enrol', [
        'token' => $enrollment->plainToken,
        'collector_uuid' => '018f0000-0000-7000-8000-000000000009',
        'public_key' => collectorPublicKey(),
    ])->assertCreated();

    expect($response->json('site_id'))->toBe(9)
        ->and($response->json('config.monitors.*.site_id'))->each->toBe(9)
        ->and($response->json('config.database'))->toBeNull();
    $this->postJson('/api/monitoring/collectors/enrol', ['token' => $enrollment->plainToken])->assertUnprocessable();
});
```

Add token hashing/expiry, certificate/public-key binding, wrong site/network/device scope, unassigned monitor omission, scoped capability omission, signed config version, monotonic sequence, heartbeat, backlog/gap, duplicate upload, out-of-order upload, clock drift, revocation, post-revocation denial, re-enrolment, and stale-data tests. Include reverse-proxy header spoofing, an untrusted proxy, certificate-fingerprint mismatch, request-signature replay, and a valid trusted-proxy/mTLS/request-signature path.

- [x] **Step 2: Run lifecycle tests and verify RED**

Run: `php artisan test tests/Feature/Monitoring/CollectorLifecycleTest.php tests/Feature/Monitoring/CollectorOutageCorrelationTest.php`

Expected: FAIL because collector control-plane services and routes do not exist.

- [x] **Step 3: Implement one-time enrolment and least-privilege configuration**

Store only an enrolment-token hash, expiry, approved site, issuing actor, and consumed time. The collector extension migration adds an ownership-consistent `collector_device_id` reference plus public-key fingerprint, client-certificate fingerprint, configuration sequence, contiguous acknowledgement, backlog/gap, revoked-at, and last-clock-drift fields. Bind the collector UUID and public key on first use, rotate the central-issued client certificate, link the collector to one canonical collector projection `Device`, and audit issuance/consumption/revocation. `CollectorConfigurationService` includes only monitors, targets, protocol settings, approved egress CIDRs, rate limits, and expiring lease references assigned to that collector/site. Sign with Ed25519 and increment `config_sequence`; never include another site's networks or devices, an application database credential, reusable session cookie, or command capability.

`CollectorTransportAuthenticator` requires both a request signature from the enrolled collector public key (method, path, body hash, timestamp, nonce) and an mTLS certificate fingerprint verified by the terminating reverse proxy. `AuthenticateMonitoringCollector` trusts the verified-certificate header only from configured proxy addresses, rejects stale/replayed nonces, and resolves exactly one active collector identity before controller code runs. Direct public requests, spoofed headers, certificate/public-key disagreement, and revoked identities all receive the same denial shape. The deployment runbook must include the proxy mTLS verification contract; Laravel must never accept an arbitrary client-supplied fingerprint header.

- [x] **Step 4: Implement ordered collector ingestion and checkpoints**

`CollectorIngestService` verifies mTLS identity plus envelope signature, approved site/network/device/collector scope, and occurrence-time drift. It accepts the next contiguous sequence, handles duplicates idempotently, returns the highest contiguous acknowledgement, parks gaps visibly, and routes observations/events through the same central envelope handlers used by direct monitoring. A collector cannot send a canonical device ID unless that device is in its signed scope and remains canonically owned by the approved site; otherwise the item enters DLQ with `site_scope_violation` or `collector_scope_violation` as appropriate.

- [x] **Step 5: Implement collector outage semantics through the canonical path**

`EvaluateCollectorHealth` runs on `monitoring-maintenance`. When heartbeat age exceeds policy, `CollectorHealthService`:

1. changes collector/path state to `unavailable`;
2. changes affected monitors' effective freshness to `stale` without changing their last reported state to failed or healthy;
3. creates one `DeviceEvent` against the collector's canonical projection device with payload containing affected site, device and monitor counts, `root_cause = collector_path`, and its immutable collector correlation key;
4. suppresses downstream availability event/ticket automation while preserving symptom evidence;
5. on contiguous recovery, records backlog age, gap count, clock drift, and recovery evidence before emitting one recovery event.

Do not call Control Room or IT services directly; the `DeviceEventObserver` path remains authoritative.

- [x] **Step 6: Run collector and correlation regression suites**

Run: `php artisan test tests/Feature/Monitoring/CollectorLifecycleTest.php tests/Feature/Monitoring/CollectorOutageCorrelationTest.php tests/Feature/Monitoring/MonitoringRecoveryPipelineTest.php tests/Feature/It/ItMonitoringTicketIntegrationTest.php tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php`

Expected: all tests pass; one outage creates one correlation, downstream data is stale, ordered return is accepted, and technician resolution remains separate.

**Task 16 verification evidence (2026-07-23, uncommitted shared worktree):** The lifecycle suite began RED at 6 failures / 0 assertions because the collector certificate, enrolment, control-plane, checkpoint, authentication, and health services did not exist. Central now stores only one-use enrolment-token hashes, expiry, approved Site, issuing actor, and consumption evidence; binds a global collector UUID to one canonical Site and one collector projection `Device`; rotates a central-issued client certificate; signs exact expiring configurations with Ed25519; and includes only valid assigned Device targets, exact `/32` or `/128` network scope, supported protocols, bounded rate limits, safe protocol settings, and one-use credential leases. Runtime requests require a trusted reverse-proxy address, verified SHA-256 client-certificate fingerprint, fresh timestamp, unique shared-store nonce, and Ed25519 method/path/timestamp/nonce/body-hash signature. Contiguous source sequences project through the existing runtime observation handler, duplicates are idempotent, gaps remain visible, ordered return clears the gap, and poison scope items advance only after minimum-necessary immutable DLQ evidence is durable. Heartbeats retain bounded backlog, byte, corruption, runtime, clock-drift, and freshness state. A stale collector changes downstream effective state to `stale` without changing last reported state, creates one root collector-path `DeviceEvent`, and restores prior effective states through one exact recovery event only after the checkpoint is contiguous. The final required collector/recovery/IT/DeviceEvent matrix passed 24 tests / 154 assertions; the isolated collector package passed 22 / 73; and the single-application/runtime-configuration gate passed 12 / 827. Strict Composer validation, exact doctor output, four-route middleware inspection, minute-level scheduler inspection, 20-file PHP syntax, scoped Pint, partition-vocabulary and diff checks passed. A reverse-proxy/CA contract is documented. Real CA issuance, a terminating mTLS proxy, shared Redis replay state, a remote Site, provider/credential-broker leases, supervised services, live protocols, load/recovery, deployment, browser, commit, and push were not exercised and remain release evidence rather than being overstated.

- [ ] **Step 7: Commit**

```powershell
git add database/migrations/2026_07_21_100005_extend_monitoring_collectors_for_runtime.php app/Domain/Monitoring/Models/MonitoringCollector.php app/Domain/Monitoring/Models/CollectorEnrollment.php app/Domain/Monitoring/Models/CollectorCheckpoint.php app/Domain/Monitoring/Services/CollectorEnrollmentService.php app/Domain/Monitoring/Services/CollectorConfigurationService.php app/Domain/Monitoring/Services/CollectorIngestService.php app/Domain/Monitoring/Services/CollectorHealthService.php app/Domain/Monitoring/Services/CollectorTransportAuthenticator.php app/Domain/Monitoring/Jobs/EvaluateCollectorHealth.php app/Http/Middleware/AuthenticateMonitoringCollector.php app/Http/Controllers/Api/Monitoring/CollectorEnrollmentController.php app/Http/Controllers/Api/Monitoring/CollectorSyncController.php routes/monitoring-collector.php bootstrap/app.php tests/Feature/Monitoring/CollectorLifecycleTest.php tests/Feature/Monitoring/CollectorOutageCorrelationTest.php
git commit -m "feat(monitoring): govern collector lifecycle and recovery"
```

## Task 17: Add time-series tiers, capacity projections, retention, and configuration snapshots

**Files:**

- Create: `database/migrations/2026_07_21_100006_create_monitoring_storage_catalog.php`
- Create: `app/Domain/Monitoring/Models/MetricSeries.php`
- Create: `app/Domain/Monitoring/Models/MetricCurrentSummary.php`
- Create: `app/Domain/Monitoring/Models/MonitoringRetentionPolicy.php`
- Create: `app/Domain/Monitoring/Models/MonitoringRetentionTombstone.php`
- Create: `app/Domain/Monitoring/Models/ConfigurationSnapshot.php`
- Create: `app/Domain/Monitoring/Contracts/TimeSeriesStore.php`
- Create: `app/Domain/Monitoring/Contracts/SnapshotStore.php`
- Create: `app/Infrastructure/Monitoring/InfluxDbTimeSeriesStore.php`
- Create: `app/Infrastructure/Monitoring/LaravelSnapshotStore.php`
- Create: `app/Domain/Monitoring/Services/MetricIngestService.php`
- Create: `app/Domain/Monitoring/Services/CapacityProjectionService.php`
- Create: `app/Domain/Monitoring/Services/RetentionEnforcer.php`
- Create: `app/Domain/Monitoring/Services/ConfigurationSnapshotService.php`
- Create: `app/Domain/Monitoring/Jobs/DownsampleMetrics.php`
- Create: `app/Domain/Monitoring/Jobs/EnforceMonitoringRetention.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/Monitoring/MetricRetentionTest.php`
- Create: `tests/Feature/Monitoring/ConfigurationSnapshotTest.php`

- [x] **Step 1: Write failing metric-pointer, tier, and tombstone tests**

```php
it('stores samples outside MySQL and retains only series pointers and current summaries', function () {
    $store = new FakeTimeSeriesStore;
    app()->instance(TimeSeriesStore::class, $store);
    $monitor = Monitor::factory()->create();

    app(MetricIngestService::class)->write($monitor, metricSample('interface.in_bytes', 1024, 'bytes', ['if_index' => '1']));

    expect($store->points)->toHaveCount(1)
        ->and(MetricSeries::firstOrFail()->external_key)->not->toBeEmpty()
        ->and(MetricCurrentSummary::firstOrFail()->value)->toBe('1024.000000')
        ->and(DB::getSchemaBuilder()->hasTable('monitor_metric_samples'))->toBeFalse();
});
```

Add site/device/dimension identity, unit conflict, duplicate timestamp/idempotency, raw→hourly→daily downsample, legal hold, privacy override, deletion tombstone without sensitive payload, restore pointer validation, capacity percentile/forecast explanation, and missing-store failure tests.

- [x] **Step 2: Run storage tests and verify RED**

Run: `php artisan test tests/Feature/Monitoring/MetricRetentionTest.php tests/Feature/Monitoring/ConfigurationSnapshotTest.php`

Expected: FAIL because storage contracts, catalog, and services do not exist.

- [x] **Step 3: Implement the storage catalog and InfluxDB adapter**

MySQL stores metric identity (site, canonical device, monitor, metric, normalised dimensions hash, unit, source, retention tier, external key), current safe summary, retention policy, snapshot metadata, and tombstones. `InfluxDbTimeSeriesStore` uses Laravel HTTP with token redaction, write idempotency tags, bounded batch size, the single configured organisation/bucket, query time range, and health check. It throws a typed unavailable exception; the runtime records collection-health impact and never treats a failed write as healthy evidence.

Bind the production contracts from `config/monitoring.php`; tests use fakes.

- [x] **Step 4: Implement tiered retention and capacity history**

`DownsampleMetrics` creates hourly p50/p95/min/max/count from raw and daily p50/p95/min/max/count from hourly. `RetentionEnforcer` applies the most restrictive applicable organisation/site/device/data-class/privacy policy unless legal hold requires preservation, deletes payloads from the external store/object store, then writes a tombstone containing IDs, class, period, policy, actor/job, and deletion time but no deleted values. `CapacityProjectionService` returns measured period, p95, slope, confidence/sample count, forecast threshold date, and an `insufficient_data` state below the configured minimum.

- [x] **Step 5: Implement governed configuration/inventory snapshots**

`ConfigurationSnapshotService` accepts only a `SnapshotCollectionCapability` or approved read-only SSH/WinRM inventory result, strips secrets through an allowlist/redactor, encrypts payloads using the configured private filesystem disk, stores SHA-256 hash/size/MIME/source/captured time/retention policy, and computes a bounded structural diff against the prior snapshot. Downloads require source-domain and site permissions. Firmware version and configuration hash project to canonical `Device`; the snapshot remains the evidence record.

- [x] **Step 6: Run storage, provider, and privacy tests**

Run: `php artisan test tests/Feature/Monitoring/MetricRetentionTest.php tests/Feature/Monitoring/ConfigurationSnapshotTest.php tests/Feature/Integrations/ProviderCapabilityMigrationTest.php tests/Feature/SecurityDevices/NetworkItWorkspaceTest.php`

Expected: all tests pass; raw samples are absent from MySQL and snapshot payloads are absent from logs/UI props.

**Task 17 verification evidence (2026-07-23, uncommitted shared worktree):** The focused RED run failed because the external time-series contract did not exist. MySQL now retains canonical Site/Device/Monitor series identity, current safe summaries, policies, snapshot metadata, and value-free tombstones while raw samples remain in the configured InfluxDB scope. External writes are bounded, idempotent, health-checked, and fail closed without advancing healthy evidence or leaking tokens. Hourly and daily p50/p95/min/max/count roll-ups, explainable capacity projections, most-restrictive retention, legal hold, external-first deletion, and restore pointer validation are implemented. Provider and approved read-only SSH/WinRM snapshots are allowlisted, recursively redacted, encrypted on a private disk, structurally diffed without values, permission- and Site-scoped on download, and projected to the canonical Device only after durable storage. Signed flow buckets and safe numeric observations project to external history without creating a parallel alert path. The final connected storage/snapshot/provider/Network & IT/inventory/listener matrix passed 34 tests / 364 assertions in 229.28 seconds. Strict Composer validation, scoped PHP syntax and Pint, route and schedule inspection, and the active partition-vocabulary gate passed. Real InfluxDB/object-store endpoints, production retention execution, restore rehearsal, load/soak, deployment, desktop browser acceptance, commit, and push remain release evidence and are not claimed.

- [ ] **Step 7: Commit**

```powershell
git add database/migrations/2026_07_21_100006_create_monitoring_storage_catalog.php app/Domain/Monitoring/Models/MetricSeries.php app/Domain/Monitoring/Models/MetricCurrentSummary.php app/Domain/Monitoring/Models/MonitoringRetentionPolicy.php app/Domain/Monitoring/Models/MonitoringRetentionTombstone.php app/Domain/Monitoring/Models/ConfigurationSnapshot.php app/Domain/Monitoring/Contracts/TimeSeriesStore.php app/Domain/Monitoring/Contracts/SnapshotStore.php app/Infrastructure/Monitoring app/Domain/Monitoring/Services/MetricIngestService.php app/Domain/Monitoring/Services/CapacityProjectionService.php app/Domain/Monitoring/Services/RetentionEnforcer.php app/Domain/Monitoring/Services/ConfigurationSnapshotService.php app/Domain/Monitoring/Jobs/DownsampleMetrics.php app/Domain/Monitoring/Jobs/EnforceMonitoringRetention.php app/Providers/AppServiceProvider.php tests/Feature/Monitoring/MetricRetentionTest.php tests/Feature/Monitoring/ConfigurationSnapshotTest.php
git commit -m "feat(monitoring): add governed telemetry retention"
```

## Task 18: Integrate real runtime state into Security & Devices operations

**Files:**

- Modify: `app/Domain/SecurityDevices/Presenters/DiscoveryOperationsPresenter.php`
- Modify: `app/Domain/SecurityDevices/Presenters/MonitoringOperationsPresenter.php`
- Modify: `app/Domain/SecurityDevices/Presenters/NetworkItWorkspacePresenter.php`
- Modify: `app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php`
- Modify: `app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php`
- Modify: `app/Domain/SecurityDevices/Http/Controllers/DiscoveryCollectorController.php`
- Modify: `app/Domain/SecurityDevices/Http/Controllers/MonitoringOperationsController.php`
- Create: `app/Domain/SecurityDevices/Http/Controllers/TopologyOperationsController.php`
- Create: `app/Domain/SecurityDevices/Http/Controllers/MonitoringRuntimeHealthController.php`
- Modify: `routes/security-devices.php`
- Modify: `resources/js/pages/security-devices/discovery.tsx`
- Modify: `resources/js/pages/security-devices/monitoring.tsx`
- Modify: `resources/js/pages/security-devices/integrations.tsx`
- Modify: `resources/js/pages/security-devices/settings.tsx`
- Modify: `resources/js/components/security-devices/network-it-workspace.tsx`
- Create: `resources/js/components/security-devices/topology-map.tsx`
- Modify: `resources/js/pages/security-devices/operations-workspaces.test.tsx`
- Modify: `resources/js/pages/security-devices/integrations-settings.test.tsx`
- Create: `resources/js/components/security-devices/topology-map.test.tsx`
- Create: `tests/Feature/SecurityDevices/MonitoringRuntimeWorkspaceTest.php`

- [x] **Step 1: Write failing permission-scoped presenter tests**

```php
it('reconciles runtime, discovery, topology, storage, and collector work without exposing secrets', function () {
    $viewer = securityDevicesViewer(siteIds: [9], permissions: ['security-devices.view']);
    seedRuntimeOperations(siteId: 9);
    seedRuntimeOperations(siteId: 10);

    $props = app(MonitoringOperationsPresenter::class)->present($viewer);
    expect($props['runtime']['queues'])->toHaveKeys(['events', 'checks', 'discovery', 'provider', 'topology', 'maintenance'])
        ->and($props['coverage']['unsupported_state'])->not->toBe('not_assessed')
        ->and($props['dependencies']['canonical_model_available'])->toBeTrue()
        ->and(json_encode($props))->not->toContain('credential', 'auth_secret', 'privacy_secret', 'raw_datagram')
        ->and(collect($props['monitors'])->pluck('site.id')->unique()->all())->toBe([9]);
});
```

Add direct-object denial, count/search/filter/export isolation, discovery candidate reasons, collector backlog/gap/revocation, provider capability absence, DLQ permission, topology evidence/confidence/change, metric retention/capacity, configuration snapshot metadata/download denial, and command-action absence tests.

- [x] **Step 2: Run backend workspace tests and verify RED**

Run: `php artisan test tests/Feature/SecurityDevices/MonitoringRuntimeWorkspaceTest.php`

Expected: FAIL because presenters still report unsupported runtime foundations.

- [x] **Step 3: Replace presentation stand-ins with canonical runtime queries**

Discovery shows scopes, immutable run counts, candidates/reasons, exclusions, collector assignment, enrol/revoke state, backlog/gaps, and exact capacity numbers. Monitoring shows effective/reported state, suppression/root cause, maintenance, coverage classification, queue lag/DLQ, time-series health, capacity evidence, and data gaps. Network & IT shows the latest topology snapshot/diff, interfaces/services/traffic, safe configuration snapshot metadata, firmware evidence, and canonical links. Integrations lists typed capabilities, version, bounds, cursor/backfill/rate-limit/partial state, credential-test state without values, and disconnect/revoke readiness. Settings & audit includes profiles, retention, compatibility exceptions, collector/replay/merge/split audit, and no raw secret fields.

Add an authenticated, permission-scoped runtime-health endpoint that reports only bounded worker heartbeat age, queue lag/dead-letter counts, listener heartbeat, time-series/object-store health, and collector aggregate state. It must not expose Redis/Influx/object-store endpoints, credentials, raw exception messages, restricted-site counts, canonical identifiers outside the viewer's scope, or payloads. Supervisor/readiness tooling uses a separate internal token/mTLS path with the same bounded response rather than bypassing application authorization.

- [x] **Step 4: Write failing frontend behavior tests**

```tsx
it('shows one root cause and keeps suppressed symptoms inspectable', () => {
    render(<MonitoringContent workspace={runtimeWorkspaceFixture} />);
    expect(screen.getByText('WAN path failed')).toBeInTheDocument();
    expect(screen.getByText('3 symptoms suppressed')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Review Control Room correlation' })).toHaveAttribute('href', '/control-room/alerts/81');
    expect(screen.queryByRole('button', { name: /unlock|restart|wipe|run command/i })).not.toBeInTheDocument();
});
```

Add empty/loading/stale/partial/error/recovery states, count-to-drill-down reconciliation, topology keyboard list fallback, confidence/evidence labels, collector unavailable versus device failed wording, retention/capacity explanation, missing capability absence, and no horizontal overflow component assertions.

- [x] **Step 5: Implement focused UI additions using existing page ownership**

Keep the existing pages and module shell. Add no duplicate navigation or device profile. `topology-map.tsx` must render an accessible device/edge list alongside the visual map, support keyboard focus and canonical device/site links, and label inferred versus reviewed edges. Use shared `StatusBadge`, `EmptyState`, `PageTabs`, and date formatting. Actions are limited to approved runtime configuration/replay/enrolment permissions; device commands are absent.

- [x] **Step 6: Run workspace and frontend tests**

Run backend: `php artisan test tests/Feature/SecurityDevices/MonitoringRuntimeWorkspaceTest.php tests/Feature/SecurityDevices/OperationsWorkspacesTest.php tests/Feature/SecurityDevices/NetworkItWorkspaceTest.php tests/Feature/SecurityDevices/IntegrationsHubTest.php tests/Feature/SecurityDevices/SettingsAuditTest.php`

Run frontend: `npm test -- resources/js/pages/security-devices/operations-workspaces.test.tsx resources/js/pages/security-devices/integrations-settings.test.tsx resources/js/components/security-devices/topology-map.test.tsx`

Expected: all tests pass and sensitive fixture sentinels are absent from Inertia props/rendered text.

**Task 18 verification evidence (2026-07-23, uncommitted shared worktree):** Runtime-backed Monitoring, Discovery, Network & IT, Integrations, and Settings & audit projections now expose bounded queue/listener/storage/collector health, dependency and topology evidence, retention/capacity/configuration state, and direct root-monitor correlation to the canonical Control Room signal/alert and visible IT incident. Recovery preserves technician ownership and never auto-closes IT work. Site-scoped presenters and direct objects omit denied records, identifiers, counts, links, payloads, endpoints, credentials, and raw exceptions. The final focused backend matrix passed 55 tests / 1,068 assertions in 519.11 seconds. Three frontend files passed 19 tests; TypeScript and scoped ESLint/Prettier passed; the client production build transformed 4,994 modules; and the SSR production build transformed 1,646 modules. No commit, push, deployment, or live production runtime is claimed.

- [ ] **Step 7: Commit**

```powershell
git add app/Domain/SecurityDevices/Presenters/DiscoveryOperationsPresenter.php app/Domain/SecurityDevices/Presenters/MonitoringOperationsPresenter.php app/Domain/SecurityDevices/Presenters/NetworkItWorkspacePresenter.php app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php app/Domain/SecurityDevices/Http/Controllers/DiscoveryCollectorController.php app/Domain/SecurityDevices/Http/Controllers/MonitoringOperationsController.php app/Domain/SecurityDevices/Http/Controllers/TopologyOperationsController.php app/Domain/SecurityDevices/Http/Controllers/MonitoringRuntimeHealthController.php routes/security-devices.php resources/js/pages/security-devices/discovery.tsx resources/js/pages/security-devices/monitoring.tsx resources/js/pages/security-devices/integrations.tsx resources/js/pages/security-devices/settings.tsx resources/js/components/security-devices/network-it-workspace.tsx resources/js/components/security-devices/topology-map.tsx resources/js/pages/security-devices/operations-workspaces.test.tsx resources/js/pages/security-devices/integrations-settings.test.tsx resources/js/components/security-devices/topology-map.test.tsx tests/Feature/SecurityDevices/MonitoringRuntimeWorkspaceTest.php
git commit -m "feat(security-devices): expose monitoring runtime operations"
```

## Task 19: Supervise, load, restore, document, and prove the runtime on desktop web

**Files:**

- Create: `ops/supervisor/oblivion-monitoring-workers.conf`
- Create: `ops/supervisor/oblivion-monitoring-listeners.conf`
- Create: `tests/Performance/Monitoring/MonitoringLoadTest.php`
- Create: `tests/Feature/Monitoring/MonitoringRestoreReconciliationTest.php`
- Create: `scripts/monitoring/verify-runtime.ps1`
- Create: `scripts/monitoring/verify-restore.ps1`
- Create: `docs/runbooks/monitoring/provider-outage.md`
- Create: `docs/runbooks/monitoring/queue-backlog-and-dlq.md`
- Create: `docs/runbooks/monitoring/false-alert-storm.md`
- Create: `docs/runbooks/monitoring/collector-outage-and-revocation.md`
- Create: `docs/runbooks/monitoring/stale-estate.md`
- Create: `docs/runbooks/monitoring/storage-restore.md`
- Create: `docs/runbooks/monitoring/runtime-and-regional-outage.md`
- Create: `tests/e2e/native-monitoring-runtime-fixtures.ts`
- Create: `tests/e2e/native-monitoring-runtime-acceptance.spec.ts`
- Modify: `playwright.config.ts`
- Modify: `docs/it-support-security-devices-completion-goal.md`
- Modify: `docs/superpowers/plans/2026-07-21-native-monitoring-runtime.md`

- [x] **Step 1: Write the failing supervisor and operational-contract test**

```php
it('supervises every runtime workload separately and preserves orchestration', function () {
    $config = file_get_contents(base_path('ops/supervisor/oblivion-monitoring-workers.conf'));
    foreach (['monitoring-events', 'monitoring-checks', 'monitoring-discovery', 'monitoring-provider', 'monitoring-topology', 'monitoring-maintenance'] as $queue) {
        expect($config)->toContain("--queue={$queue}");
    }
    expect($config)->not->toContain('--queue=monitoring,monitoring-events')
        ->and($config)->not->toContain('--queue=default');
});
```

Add assertions for worker names, Redis connection, retry/backoff/timeout/memory bounds, stop-wait seconds greater than job timeout, distinct UDP listener processes, runtime health endpoints, and no database variables in collector deployment documentation.

- [x] **Step 2: Run the operational-contract test and verify RED**

Run: `php artisan test tests/Performance/Monitoring/MonitoringLoadTest.php`

Expected: FAIL because supervisor configuration and load fixtures do not exist.

- [x] **Step 3: Add separately supervised worker and listener definitions**

Use one Supervisor program group per queue, for example:

```ini
[program:oblivion-monitoring-events]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/oblivionfindings/artisan queue:work redis --queue=monitoring-events --sleep=1 --tries=5 --backoff=5,30,120 --timeout=60 --memory=256 --max-time=3600
numprocs=4
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=90
redirect_stderr=true
stdout_logfile=/var/log/oblivion/monitoring-events.log
```

Create corresponding programs for checks (8 processes/45-second timeout), discovery (2/300), provider (3/180), topology (2/300), and maintenance (1/300). Keep the existing separately supervised `monitoring` orchestration worker unchanged. Listener programs run one process each for `monitoring:listen-snmp-traps`, `monitoring:listen-syslog`, and `monitoring:listen-flow`, with automatic restart and dedicated logs.

- [x] **Step 4: Implement reproducible load and outage proof**

`MonitoringLoadTest.php` seeds one organisation with 100 sites, 10,000 canonically owned devices, 50,000 monitors, 500 collectors, restricted-role site grants, and synthetic signed envelopes without performing network I/O. It measures dispatch, ingest, correlation, projection, queue-lag, topology, and downsample phases; asserts no site-scope or ownership leakage and no duplicate correlations; and writes JSON results to `output/monitoring/load/` only when `MONITORING_WRITE_EVIDENCE=1`.

Run:

```powershell
$env:MONITORING_WRITE_EVIDENCE='1'
php artisan test tests/Performance/Monitoring/MonitoringLoadTest.php --profile
Remove-Item Env:MONITORING_WRITE_EVIDENCE
```

Expected: 50,000 check dispatches complete without duplicate schedule keys; 100,000 duplicate-delivered envelopes create 50,000 inbox effects; p95 synthetic ingestion/correlation/projection timings and peak memory are written to the evidence JSON; every threshold configured in `config/monitoring.php` passes.

- [ ] **Step 5: Implement restore and reconciliation rehearsal**

`verify-restore.ps1` accepts explicit restored MySQL, Redis, InfluxDB, and object-store endpoints; refuses production hostnames unless `-AllowProductionReadOnly` is supplied; runs migrations in pretend mode; checks outbox/inbox/checkpoint continuity, time-series pointers, object hashes, latest topology snapshots, and collector config sequences; then runs `MonitoringRestoreReconciliationTest.php` against the restored isolated environment.

Run:

```powershell
pwsh scripts/monitoring/verify-restore.ps1 -MySqlDsn $env:MONITORING_RESTORE_MYSQL_DSN -RedisUrl $env:MONITORING_RESTORE_REDIS_URL -InfluxUrl $env:MONITORING_RESTORE_INFLUX_URL -ObjectDisk monitoring-restore
```

Expected: exit 0 with `outbox_gap=0`, `orphan_series=0`, `snapshot_hash_mismatch=0`, `topology_pointer_gap=0`, and `collector_sequence_regression=0` in the generated reconciliation report.

- [x] **Step 6: Write executable runbooks**

Each runbook must contain: trigger/alert names, customer-visible symptoms, distinction between device/collector/site-path/runtime/storage failures, safe read-only diagnosis commands, containment that preserves evidence, replay/recovery sequence, validation queries, escalation owner, rollback or forward-repair rule, and closure evidence. The false-alert-storm runbook may pause notification/ticket automation under audited maintenance policy but must not delete observations. The compromised/revoked collector path revokes identity, rejects future envelopes, preserves DLQ/audit, rotates central trust, and verifies unrelated approved sites and collectors are unaffected. Failed device commands are explicitly linked to the separate command runbook and are not exercised here.

- [x] **Step 7: Write and run desktop-web-only browser acceptance**

The fixture creates one organisation with an allowed site, a denied site, restricted and privileged roles, unrelated canonically owned devices, and these exact states: direct ICMP failure with three dependent suppressed symptoms and one Control Room/IT link; remote collector outage with stale devices, backlog and sequence gap; completed discovery run with matched/proposed/ambiguous candidates; topology add/remove change; provider partial page/rate limit; expiring TLS; capacity projection; configuration snapshot metadata; and a DLQ replayable item.

The Playwright spec must run at 1440×900 and 1280×800 and assert:

- Monitoring finding → root-cause evidence → one Control Room correlation → one IT incident → confirmed recovery without technician auto-resolution;
- collector outage is labelled collector/site-path unavailable, downstream data is stale, one finding is counted, and ordered return clears the gap;
- discovery counts reconcile to candidate rows and canonical Device links;
- topology has visual and keyboard-readable evidence/confidence/change views;
- Integrations and Settings & audit show typed capability, rate/backfill, retention, queue/DLQ, and audit state;
- no secret, raw datagram, credential material, command button, console error, failed request, or horizontal overflow appears;
- denied-site, unowned, and privacy-restricted records cannot be inferred by direct URL, forged identifier, count, filter, export, or response body.

Run:

```powershell
npx playwright test tests/e2e/native-monitoring-runtime-acceptance.spec.ts --project=chromium-desktop
```

Expected: all desktop projects pass. Do not run or claim Pixel/mobile/WebView evidence in this plan.

- [x] **Step 8: Run the full automated verification matrix**

```powershell
php artisan test tests/Feature/Monitoring tests/Unit/Monitoring tests/Feature/Integrations tests/Unit/Integration tests/Feature/SecurityDevices tests/Feature/It/ItMonitoringTicketIntegrationTest.php tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php tests/Performance/Monitoring
Push-Location collector
composer validate --strict
vendor/bin/pest
Pop-Location
php artisan wayfinder:generate
npm test
npm run types
npm run lint
npm run format:check
npm run build
npx vite build --ssr
vendor/bin/pint --dirty
git diff --check
```

Expected: every command exits 0. If repository-wide lint or format reports pre-existing unrelated failures, record the exact files/count and separately run targeted ESLint/Prettier on every runtime-plan frontend file; do not rewrite unrelated files or mark V08 complete.

- [x] **Step 9: Record exact evidence without overstating completion**

Update the completion ledger only after the commands above run. Mark A01–A10, L05–L06, S08–S10, E04, E08, and V01–V10 individually only where their complete definitions are proven. Leave M02–M05, E07, and command/security portions of V04/V09 open for the device-command, secrets, hardening, and closeout plans. Mobile is not an acceptance requirement for this web application and must not be added as an implicit open gate. Record commit IDs, exact test/assertion counts, load thresholds/results, restore result, desktop viewport results, and separate merged/pushed/deployed state.

**Task 19 verification evidence (2026-07-23, uncommitted shared worktree):** The full synthetic evidence profile passed at 100 Sites, 10,000 canonical Devices, 50,000 monitors, and 500 collectors. The operational/load suite passed 4 tests / 124 assertions in 208.13 seconds and wrote `output/monitoring/load/native-monitoring-load-20260723UTC162657Z.json`: dispatch p95 0.159 ms, ingest p95 1.168 ms, correlation p95 0.132 ms, projection p95 0.039 ms, queue-lag p95 0.023 ms, topology p95 0.189 ms, downsample p95 0.069 ms, 118 MB peak memory, 50,000 unique schedule keys, and 100,000 duplicate deliveries producing exactly 50,000 inbox effects. Automated restore reconciliation passed 2 tests / 16 assertions in 214.32 seconds; a real rehearsal against restored MySQL, Redis, InfluxDB, and object-store endpoints remains open and Step 5 is deliberately unchecked. Seven executable runbooks and separate bounded Supervisor workers/listeners are present. Exact-worktree Playwright used fresh production assets and an isolated MySQL fixture database; both desktop journeys passed in 2.7 minutes at 1440×900 and 1280×800, including root-cause-to-Control-Room-to-IT recovery, collector outage/ordered recovery, discovery, visual and keyboard topology, integration/runtime, retention/DLQ/audit, secret absence, failed-request/console checks, horizontal overflow, and concealed denied-Site direct objects. The authoritative connected backend matrix passed 874 tests / 7,623 assertions in 1,723.02 seconds. The standalone collector passed strict Composer validation and 22 tests / 73 assertions. The full frontend suite passed 109 files / 432 tests; the refreshed focused runtime suite passed 3 files / 19 tests; Wayfinder generation, TypeScript, scoped ESLint/Prettier, scoped Pint, `git diff --check`, the 4,994-module client build, and the 1,646-module SSR build passed. Repository-wide `npm run format:check` remains red at 1,305 pre-existing files, and repository-wide `npm run lint` remains red because its `eslint .` scope includes third-party `collector/vendor` minified JavaScript (2,522 errors) plus one unrelated HR source warning after the runtime warning was fixed; the complete runtime frontend scope passes ESLint with zero warnings. V08 remains open without a bulk rewrite or vendor-scope configuration change. Live supervised endpoints, a real restored-service rehearsal, commit, push, and deployment were not executed or claimed.

- [ ] **Step 10: Mark only executed plan steps complete and commit evidence**

Change only successfully executed checkboxes in this file from `- [ ]` to `- [x]`.

```powershell
git add ops/supervisor/oblivion-monitoring-workers.conf ops/supervisor/oblivion-monitoring-listeners.conf tests/Performance/Monitoring/MonitoringLoadTest.php tests/Feature/Monitoring/MonitoringRestoreReconciliationTest.php scripts/monitoring docs/runbooks/monitoring tests/e2e/native-monitoring-runtime-fixtures.ts tests/e2e/native-monitoring-runtime-acceptance.spec.ts playwright.config.ts docs/it-support-security-devices-completion-goal.md docs/superpowers/plans/2026-07-21-native-monitoring-runtime.md
git commit -m "docs(monitoring): record runtime acceptance evidence"
```

## Requirement-to-task closure map

| Requirement | Implementing tasks | Primary proof |
| --- | --- | --- |
| Same-repository PHP 8.4 runtime and isolated workers | 1, 6, 19 | runtime configuration, scheduler, supervisor contract |
| Versioned signed envelope, inbox/outbox/checkpoint/DLQ/replay/order | 2–3 | delivery feature suite |
| SSRF/egress and site/network/device ownership invariants | 4, 7–9, 16, 18 | egress, discovery, collector, workspace denial suites |
| ICMP/TCP/DNS/HTTP/TLS | 5–6 | direct adapter and job suites |
| Discovery scopes/runs/candidates/identity/merge/split | 7–8 | discovery identity and run suites |
| SNMPv3/traps/syslog/flow and approved SSH/WinRM | 9–11 | protocol fixture and listener-boundary suites |
| Typed provider capabilities | 12 | provider migration and existing integration suites |
| Topology snapshots and dependency suppression | 13–14 | topology and suppression suites |
| Collector enrolment/scope/buffer/order/revocation/outage | 15–16 | collector package/lifecycle/outage suites |
| Time series/retention/configuration snapshots/capacity | 17 | metric retention and snapshot suites |
| Operational UI integration | 18 | permission-scoped backend and Vitest suites |
| Load/restore/runbooks/desktop web | 19 | performance, reconciliation, runbook, and Playwright evidence |
| One canonical Device and Control Room path | 5, 7, 9, 14, 16, 19 | Device registry, DeviceEvent signal, and IT integration regressions |
| Commands/secrets/high-risk controls excluded | 1, 9, 11, 15, 18–19 | rejecting command port, unavailable lease provider, absence assertions |

## Execution decision

Execute this plan from its dedicated worktree. Use `superpowers:subagent-driven-development` when task isolation is available, or `superpowers:executing-plans` inline, with `superpowers:test-driven-development` for every implementation task and `superpowers:verification-before-completion` before each completion claim. Do not merge, push, deploy, or mark master-ledger acceptance without the user's explicit authority and the exact evidence required above.
