<?php

use App\Domain\Monitoring\Contracts\RuntimeEnvelopeHandler;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\ConsumeMonitoringEnvelope;
use App\Domain\Monitoring\Jobs\PublishMonitoringOutbox;
use App\Domain\Monitoring\Jobs\ReplayMonitoringDeadLetter;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringConsumerCheckpoint;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringInbox;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\MonitoringDeliveryRecoveryService;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\Monitoring\Services\RuntimeEnvelopeHandlerRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    RefreshDatabaseState::$migrated = true;
}

beforeEach(function () {
    config()->set('monitoring.signing', [
        'active_key_id' => 'delivery-test-key',
        'keys' => [
            'delivery-test-key' => base64_encode(str_repeat("\x2a", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);

    Permission::firstOrCreate(
        ['key' => 'securityDevices.integrations.manage'],
        [
            'description' => 'Manage device sync and discovery',
            'group' => 'security_devices',
            'module' => 'Security & Devices',
        ],
    );
});

function deliveryEnvelope(
    int $sequence,
    string $messageId,
    ?string $idempotencyKey = null,
    array $payload = [],
): string {
    $now = CarbonImmutable::parse('2026-07-21T01:02:03.456789Z');
    $envelope = new RuntimeEnvelope(
        schemaVersion: 1,
        messageId: $messageId,
        type: RuntimeMessageType::Observation,
        source: 'central:checks',
        sequence: $sequence,
        occurredAt: $now,
        ingestedAt: $now,
        idempotencyKey: $idempotencyKey ?? "observation:{$sequence}",
        traceId: '018f0000-0000-7000-8000-000000000099',
        payload: $payload,
    );

    return app(RuntimeEnvelopeCodec::class)->encode($envelope);
}

function deliveryConsumer(RuntimeEnvelopeHandler $handler): MonitoringEnvelopeConsumer
{
    app()->instance(
        RuntimeEnvelopeHandlerRegistry::class,
        new RuntimeEnvelopeHandlerRegistry([
            RuntimeMessageType::Observation->value => [1 => $handler, 2 => $handler],
        ]),
    );

    return app(MonitoringEnvelopeConsumer::class);
}

it('processes a duplicate once and parks a sequence gap without advancing the checkpoint', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    $consumer = deliveryConsumer($handler);
    $site = Site::factory()->create();
    $one = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000001');
    $three = deliveryEnvelope(3, '018f0000-0000-7000-8000-000000000003');

    $consumer->consume('observation-projector', $one, $site->id);
    $consumer->consume('observation-projector', $one, $site->id);
    $consumer->consume('observation-projector', $three, $site->id);

    $checkpoint = MonitoringConsumerCheckpoint::firstOrFail();

    expect(MonitoringInbox::count())->toBe(2)
        ->and($checkpoint->last_sequence)->toBe(1)
        ->and($checkpoint->gap_from)->toBe(2)
        ->and($checkpoint->gap_to)->toBe(2)
        ->and(MonitoringDeadLetter::where('reason_code', 'sequence_gap')->count())->toBe(1)
        ->and(MonitoringDeadLetter::firstOrFail()->site_id)->toBe($site->id);
});

it('parks a reused idempotency key without invoking the handler twice', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    $consumer = deliveryConsumer($handler);

    $consumer->consume('observation-projector', deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000011',
        'duplicate-key',
    ));
    $consumer->consume('observation-projector', deliveryEnvelope(
        2,
        '018f0000-0000-7000-8000-000000000012',
        'duplicate-key',
    ));

    expect(MonitoringDeadLetter::where('reason_code', 'payload_invalid')->count())->toBe(1)
        ->and(MonitoringConsumerCheckpoint::firstOrFail()->last_sequence)->toBe(1);
});

it('checks an incoming hash collision before the processed shortcut', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    $consumer = deliveryConsumer($handler);
    $messageId = '018f0000-0000-7000-8000-000000000021';

    $consumer->consume('observation-projector', deliveryEnvelope(1, $messageId, payload: ['state' => 'healthy']));
    $consumer->consume('observation-projector', deliveryEnvelope(1, $messageId, payload: ['state' => 'failed']));

    expect(MonitoringDeadLetter::where('reason_code', 'payload_invalid')->count())->toBe(1)
        ->and(MonitoringInbox::firstOrFail()->processed_at)->not->toBeNull();
});

it('parks unsupported versions and invalid signatures without trusting payload site context', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldNotReceive('handle');
    $consumer = deliveryConsumer($handler);
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $valid = deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000031',
        payload: ['site_id' => 999999],
    );
    $unsupported = str_replace('"schema_version":1', '"schema_version":99', $valid);
    $invalidSignature = str_replace('"site_id":999999', '"site_id":999998', $valid);

    $consumer->consume('observation-projector', $unsupported);
    $consumer->consume('observation-projector', $invalidSignature, $site->id);
    $consumer->consume('observation-projector', $invalidSignature, $site->id);
    $consumer->consume('observation-projector', $invalidSignature, $otherSite->id);

    expect(MonitoringDeadLetter::where('reason_code', 'unsupported_version')->count())->toBe(1)
        ->and(MonitoringDeadLetter::where('reason_code', 'invalid_signature')->count())->toBe(2)
        ->and(MonitoringDeadLetter::where('reason_code', 'unsupported_version')->firstOrFail()->site_id)->toBeNull()
        ->and(MonitoringDeadLetter::where('reason_code', 'invalid_signature')->pluck('site_id')->sort()->values()->all())
        ->toBe(collect([$site->id, $otherSite->id])->sort()->values()->all())
        ->and(MonitoringDeadLetter::where('reason_code', 'invalid_signature')->firstOrFail()->evidence_fingerprint)
        ->toBe(hash('sha256', $invalidSignature));
});

it('parks an authenticated but unsupported payload contract version for operator recovery', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldNotReceive('handle');
    $consumer = deliveryConsumer($handler);
    $now = CarbonImmutable::parse('2026-07-21T01:02:03.456789Z');
    $encoded = app(RuntimeEnvelopeCodec::class)->encode(new RuntimeEnvelope(
        schemaVersion: 2,
        messageId: '018f0000-0000-7000-8000-000000000032',
        type: RuntimeMessageType::Observation,
        source: 'central:checks',
        sequence: 1,
        occurredAt: $now,
        ingestedAt: $now,
        idempotencyKey: 'unsupported-payload:1',
        traceId: '018f0000-0000-7000-8000-000000000033',
        payload: ['state' => 'healthy'],
        payloadVersion: 99,
    ));

    $consumer->consume('observation-projector', $encoded);

    expect(MonitoringDeadLetter::where('reason_code', 'unsupported_version')->count())->toBe(1)
        ->and(MonitoringDeadLetter::firstOrFail()->reason_message)
        ->toBe('Envelope payload version is unsupported.');
});

it('uses deterministic malformed evidence identity and verifies exact bytes on a dedupe conflict', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldNotReceive('handle');
    $consumer = deliveryConsumer($handler);
    $malformed = '{not-json';

    $consumer->consume('observation-projector', $malformed);
    $consumer->consume('observation-projector', $malformed);

    $letter = MonitoringDeadLetter::where('envelope_bytes', $malformed)->firstOrFail();

    expect(MonitoringDeadLetter::where('envelope_bytes', $malformed)->count())->toBe(1)
        ->and(Str::isUuid($letter->message_id))->toBeTrue()
        ->and($letter->evidence_fingerprint)->toBe(hash('sha256', $malformed));

    DB::table('monitoring_dead_letters')->where('id', $letter->id)->update([
        'envelope_bytes' => '{different-stored-bytes',
    ]);

    expect(fn () => $consumer->consume('observation-projector', $malformed))
        ->toThrow(UnexpectedValueException::class, 'Dead-letter evidence identity conflict.');
});

it('parks a handler failure only after the finite queue retry lifecycle is exhausted', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new RuntimeException('secret provider response'));
    $consumer = deliveryConsumer($handler);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000041');
    $job = new ConsumeMonitoringEnvelope('observation-projector', $encoded);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 30, 120]);
    expect(fn () => $job->handle($consumer))->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('secret provider response'));

    $letter = MonitoringDeadLetter::where('reason_code', 'handler_failed')->firstOrFail();

    expect($letter->reason_message)->toBe('Handler retry limit was exhausted.')
        ->and($letter->reason_message)->not->toContain('secret')
        ->and(MonitoringInbox::count())->toBe(0);
});

it('does not park a stale failed hook after the exact message was processed', function () {
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    $consumer = deliveryConsumer($handler);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000042');

    $consumer->consume('observation-projector', $encoded);
    (new ConsumeMonitoringEnvelope('observation-projector', $encoded))
        ->failed(new RuntimeException('stale worker failure'));

    expect(MonitoringInbox::firstOrFail()->processed_at)->not->toBeNull()
        ->and(MonitoringDeadLetter::count())->toBe(0);
});

it('classifies semantic observation payload errors as payload invalid', function () {
    app(MonitoringEnvelopeConsumer::class)->consume(
        'observation-projector',
        deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000043', payload: []),
    );

    expect(MonitoringDeadLetter::where('reason_code', 'payload_invalid')->count())->toBe(1)
        ->and(MonitoringDeadLetter::where('reason_code', 'handler_failed')->count())->toBe(0);
});

it('replays exact signed bytes after the missing sequence arrives', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->times(3);
    $consumer = deliveryConsumer($handler);

    $one = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000051');
    $two = deliveryEnvelope(2, '018f0000-0000-7000-8000-000000000052');
    $three = deliveryEnvelope(3, '018f0000-0000-7000-8000-000000000053');
    $consumer->consume('observation-projector', $one, $site->id);
    $consumer->consume('observation-projector', $three, $site->id);
    $letter = MonitoringDeadLetter::where('reason_code', 'sequence_gap')->firstOrFail();
    $consumer->consume('observation-projector', $two, $site->id);

    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->twice()->with(Mockery::type(User::class))->andReturn([$site->id]);
    $service = new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, $consumer);
    $service->replay($actor, $letter, 'Missing sequence restored');

    Queue::assertPushed(ReplayMonitoringDeadLetter::class, function (ReplayMonitoringDeadLetter $job) use ($service, $letter): bool {
        expect($job->deadLetterId)->toBe($letter->id);
        $job->handle($service);

        return true;
    });

    $completedJob = Queue::pushed(ReplayMonitoringDeadLetter::class)->first();
    $completedJob->handle($service);

    expect(MonitoringConsumerCheckpoint::firstOrFail()->last_sequence)->toBe(3)
        ->and($letter->fresh()->replay_count)->toBe(1)
        ->and($letter->fresh()->resolved_at)->not->toBeNull()
        ->and($letter->fresh()->resolved_by_user_id)->toBe($actor->id)
        ->and($letter->fresh()->resolution_reason)->toBe('Missing sequence restored')
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replayed')->where('user_id', $actor->id)->count())->toBe(1);

    expect(fn () => $service->discard($actor, $letter, 'Already replayed'))
        ->toThrow(UnexpectedValueException::class, 'already resolved');
});

it('discards without deleting and audits the explicit actor and reason', function () {
    $site = Site::factory()->create();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(4, '018f0000-0000-7000-8000-000000000061');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000061',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 4,
        'idempotency_key' => 'observation:4',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 2.',
        'envelope_bytes' => $encoded,
        'site_id' => $site->id,
    ]);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->once()->with($actor)->andReturn([$site->id]);

    (new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, app(MonitoringEnvelopeConsumer::class)))
        ->discard($actor, $letter, 'Superseded by canonical sample');

    expect(MonitoringDeadLetter::whereKey($letter->id)->exists())->toBeTrue()
        ->and($letter->fresh()->resolved_by_user_id)->toBe($actor->id)
        ->and($letter->fresh()->resolution_reason)->toBe('Superseded by canonical sample')
        ->and(AuditLog::where('action', 'monitoring.dead_letter.discarded')->where('user_id', $actor->id)->count())->toBe(1);
});

it('denies replay without operate permission or canonical target access', function () {
    $site = Site::factory()->create();
    $device = Device::factory()->create();
    $encoded = deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000071',
        payload: ['device_id' => $device->id],
    );
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000071',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
        'site_id' => $site->id,
    ]);
    $deniedActor = User::factory()->create();
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldNotReceive('accessibleSiteIds');
    $service = new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, app(MonitoringEnvelopeConsumer::class));

    expect(fn () => $service->replay($deniedActor, $letter, 'Try replay'))
        ->toThrow(AuthorizationException::class);

    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $targetAccess = Mockery::mock(SecurityDevicesAccessService::class);
    $targetAccess->shouldReceive('accessibleSiteIds')->once()->andReturn([$site->id]);
    $targetAccess->shouldReceive('assertCanViewDevice')->once()->andThrow(new AuthorizationException('restricted'));

    expect(fn () => (new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $targetAccess, app(MonitoringEnvelopeConsumer::class)))
        ->replay($actor, $letter, 'Try protected target'))
        ->toThrow(AuthorizationException::class, 'restricted')
        ->and($letter->fresh()->replay_count)->toBe(0);
});

it('allocates publisher sequences under an explicit test lock and fails closed without one', function () {
    Queue::fake();
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    $publisher = app(MonitoringOutboxPublisher::class);
    $domainSequences = [];

    $first = $publisher->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:publisher-test',
        'publisher:1',
        ['state' => 'healthy'],
        function (RuntimeEnvelope $envelope) use (&$domainSequences): void {
            $domainSequences[] = $envelope->sequence;
        },
    );
    $second = $publisher->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:publisher-test',
        'publisher:2',
        ['state' => 'healthy'],
    );

    expect([$first->sequence, $second->sequence])->toBe([1, 2])
        ->and($domainSequences)->toBe([1])
        ->and(MonitoringOutbox::where('source', 'central:publisher-test')->count())->toBe(2);
    Queue::assertPushed(PublishMonitoringOutbox::class, 2);

    $same = $publisher->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:publisher-test',
        'publisher:1',
        ['state' => 'healthy'],
        function () use (&$domainSequences): void {
            $domainSequences[] = 999;
        },
    );

    expect($same->id)->toBe($first->id)
        ->and($domainSequences)->toBe([1])
        ->and(MonitoringOutbox::where('source', 'central:publisher-test')->count())->toBe(2);
    Queue::assertPushed(PublishMonitoringOutbox::class, 3);

    expect(fn () => $publisher->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:publisher-test',
        'publisher:1',
        ['state' => 'failed'],
    ))->toThrow(UnexpectedValueException::class, 'idempotency key was reused');
    expect(MonitoringOutbox::where('source', 'central:publisher-test')->count())->toBe(2);

    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', false);
    expect(fn () => $publisher->stage(
        RuntimeMessageType::Event,
        'events',
        'central:unsafe-lock',
        'unsafe:1',
        [],
    ))->toThrow(RuntimeException::class, 'requires a shared Redis lock store');
});

it('treats integral floats as the same canonical payload on an idempotent stage retry', function () {
    Queue::fake();
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    $publisher = app(MonitoringOutboxPublisher::class);
    $domainChanges = 0;
    $payload = ['one' => 1.0, 'negative_zero' => -0.0, 'nested' => ['zero' => 0.0]];

    $first = $publisher->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:canonical-float',
        'canonical-float:1',
        $payload,
        function () use (&$domainChanges): void {
            $domainChanges++;
        },
    );
    $retry = $publisher->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:canonical-float',
        'canonical-float:1',
        $payload,
        function () use (&$domainChanges): void {
            $domainChanges++;
        },
    );

    expect($retry->id)->toBe($first->id)
        ->and($domainChanges)->toBe(1)
        ->and(MonitoringOutbox::where('source', 'central:canonical-float')->count())->toBe(1);
});

it('marks only acknowledged outbox dispatches published and uses finite retry settings', function () {
    Queue::fake();
    $publisher = app(MonitoringOutboxPublisher::class);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000081');
    $outbox = MonitoringOutbox::create([
        'message_id' => '018f0000-0000-7000-8000-000000000081',
        'stream' => 'checks',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'envelope_bytes' => $encoded,
        'available_at' => now(),
    ]);

    $token = app(MonitoringDeliveryRecoveryService::class)->claimOutbox($outbox);
    $publisher->publish($outbox->id, $token);

    Queue::assertPushed(ConsumeMonitoringEnvelope::class, fn (ConsumeMonitoringEnvelope $job): bool => $job->consumer === 'observation-projector' && $job->envelopeBytes === $encoded);
    expect($outbox->fresh()->published_at)->not->toBeNull()
        ->and($outbox->fresh()->attempts)->toBe(1)
        ->and((new PublishMonitoringOutbox($outbox->id, 'test-token'))->tries)->toBe(5)
        ->and((new PublishMonitoringOutbox($outbox->id, 'test-token'))->backoff)->toBe([5, 30, 120, 300]);
});

it('recovers a committed outbox row after initial queue acceptance fails', function () {
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    $originalDispatcher = app(Dispatcher::class);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);

    expect(fn () => app(MonitoringOutboxPublisher::class)->stage(
        RuntimeMessageType::Observation,
        'checks',
        'central:recovery-test',
        'recovery:1',
        ['state' => 'healthy'],
    ))->toThrow(RuntimeException::class, 'queue unavailable');

    $outbox = MonitoringOutbox::where('source', 'central:recovery-test')->firstOrFail();
    expect($outbox->published_at)->toBeNull()
        ->and($outbox->dispatch_token)->not->toBeNull();
    $failedToken = $outbox->dispatch_token;

    app()->instance(Dispatcher::class, $originalDispatcher);
    Queue::fake();
    $outbox->forceFill(['dispatch_lease_until' => now()->subSecond()])->save();

    $claimed = app(MonitoringDeliveryRecoveryService::class)->recover();

    expect($claimed['outbox'])->toBe(1);
    Queue::assertPushed(PublishMonitoringOutbox::class, fn (PublishMonitoringOutbox $job): bool => $job->outboxId === $outbox->id
        && $job->dispatchToken !== $outbox->dispatch_token);

    app(MonitoringOutboxPublisher::class)->publish($outbox->id, $failedToken);
    expect($outbox->fresh()->published_at)->toBeNull();

    $recoveredJob = Queue::pushed(PublishMonitoringOutbox::class)->first();
    $recoveredJob->handle(app(MonitoringOutboxPublisher::class));
    expect($outbox->fresh()->published_at)->not->toBeNull();
});

it('keeps outbox identity immutable across model and bulk writes', function () {
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000085');
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000085',
        'stream' => 'checks',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'outbox-immutable:1',
        'envelope_bytes' => $encoded,
        'available_at' => now(),
    ];
    $outbox = MonitoringOutbox::create($attributes);

    $normal = $outbox->fresh();
    $normal->source = 'changed-source';
    expect(fn () => $normal->save())->toThrow(UnexpectedValueException::class, 'are immutable');

    $quiet = $outbox->fresh();
    $quiet->envelope_bytes = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000086');
    expect(fn () => $quiet->saveQuietly())->toThrow(UnexpectedValueException::class, 'are immutable');

    expect(fn () => MonitoringOutbox::query()->whereKey($outbox->id)->update([
        'idempotency_key' => 'changed',
    ]))->toThrow(UnexpectedValueException::class, 'are immutable');

    expect(fn () => MonitoringOutbox::query()->upsert(
        [$attributes],
        ['message_id'],
        ['envelope_bytes'],
    ))->toThrow(UnexpectedValueException::class, 'are immutable');

    expect(fn () => MonitoringOutbox::query()->insertUsing(
        array_keys($attributes),
        MonitoringOutbox::query()->select(array_keys($attributes)),
    ))->toThrow(UnexpectedValueException::class, 'insert-from-query');

    MonitoringOutbox::query()->whereKey($outbox->id)->update(['last_error' => 'Lifecycle update allowed.']);
    expect($outbox->fresh()->last_error)->toBe('Lifecycle update allowed.');
});

it('rejects a valid signed envelope whose identity does not match its outbox row', function () {
    Queue::fake();
    $original = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000087');
    $substituted = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000088');
    $outbox = MonitoringOutbox::create([
        'message_id' => '018f0000-0000-7000-8000-000000000087',
        'stream' => 'checks',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'envelope_bytes' => $original,
        'available_at' => now(),
    ]);
    DB::table('monitoring_outbox')->where('id', $outbox->id)->update([
        'envelope_bytes' => $substituted,
    ]);
    $token = app(MonitoringDeliveryRecoveryService::class)->claimOutbox($outbox);

    expect(fn () => app(MonitoringOutboxPublisher::class)->publish($outbox->id, $token))
        ->toThrow(UnexpectedValueException::class, 'identity does not match')
        ->and($outbox->fresh()->published_at)->toBeNull()
        ->and($outbox->fresh()->last_error)->toBe('Publish attempt failed.');
    Queue::assertNotPushed(ConsumeMonitoringEnvelope::class);
});

it('requires an explicit CLI actor before replaying a dead letter', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000091');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000091',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
    ]);

    expect(Artisan::call('monitoring:replay-dead-letter', [
        'letter' => $letter->id,
        '--reason' => 'Missing sample restored',
    ]))->toBe(1)
        ->and($letter->fresh()->replay_count)->toBe(0);

    expect(Artisan::call('monitoring:replay-dead-letter', [
        'letter' => $letter->id,
        '--actor' => $actor->id,
        '--reason' => 'Missing sample restored',
    ]))->toBe(0)
        ->and($letter->fresh()->replay_count)->toBe(0)
        ->and($letter->fresh()->replay_requested_by_user_id)->toBe($actor->id)
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replay_requested')->where('user_id', $actor->id)->count())->toBe(1);
});

it('registers the monitoring delivery recovery sweep every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'monitoring:recover-delivery'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('parks typed site and target scope violations before observation mutation', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $monitor = Monitor::factory()->create();
    DeviceAssignment::create([
        'device_id' => $monitor->device_id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);
    $consumer = app(MonitoringEnvelopeConsumer::class);
    $basePayload = [
        'monitor_id' => $monitor->id,
        'device_id' => $monitor->device_id,
        'site_id' => $site->id,
        'source_key' => 'scope-test:1',
        'state' => 'healthy',
        'observed_at' => '2026-07-21T01:02:03.456789Z',
    ];

    $consumer->consume('observation-projector', deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000101',
        payload: [...$basePayload, 'device_id' => $monitor->device_id + 999],
    ), $site->id);
    $consumer->consume('observation-projector', deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000102',
        idempotencyKey: 'scope-site:1',
        payload: $basePayload,
    ), $otherSite->id);
    $consumer->consume('observation-projector', deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000104',
        idempotencyKey: 'scope-direct-collector:1',
        payload: [...$basePayload, 'collector_uuid' => '018f0000-0000-7000-8000-000000000104'],
    ), $site->id);

    $collector = MonitoringCollector::factory()->create(['site_id' => $otherSite->id]);
    $monitor->forceFill(['collector_id' => $collector->id])->save();
    $consumer->consume('observation-projector', deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000103',
        idempotencyKey: 'scope-collector:1',
        payload: [...$basePayload, 'site_id' => $otherSite->id],
    ), $otherSite->id);

    expect(MonitoringDeadLetter::where('reason_code', 'scope_violation')->count())->toBe(3)
        ->and(MonitoringDeadLetter::where('reason_code', 'site_scope_violation')->count())->toBe(1)
        ->and($monitor->observations()->count())->toBe(0)
        ->and(MonitoringConsumerCheckpoint::firstOrFail()->last_sequence)->toBe(0);
});

it('allows an authorised operator to discard poison bytes without decoding them', function () {
    $site = Site::factory()->create();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000111',
        'consumer' => 'observation-projector',
        'source' => 'untrusted',
        'sequence' => 1,
        'idempotency_key' => 'invalid:111',
        'reason_code' => 'invalid_signature',
        'reason_message' => 'Envelope authentication failed.',
        'envelope_bytes' => '{"invalid":true}',
        'site_id' => $site->id,
    ]);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->once()->with($actor)->andReturn([$site->id]);

    (new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, app(MonitoringEnvelopeConsumer::class)))
        ->discard($actor, $letter, 'Poison evidence reviewed');

    expect($letter->fresh()->resolved_at)->not->toBeNull()
        ->and($letter->fresh()->resolution_reason)->toBe('Poison evidence reviewed');
});

it('records no replay success when queue acceptance fails', function () {
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000121');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000121',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
    ]);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->once()->with($actor)->andReturn([]);
    $originalDispatcher = app(Dispatcher::class);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);

    expect(fn () => (new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, app(MonitoringEnvelopeConsumer::class)))
        ->replay($actor, $letter, 'Retry after outage'))
        ->toThrow(RuntimeException::class, 'queue unavailable')
        ->and($letter->fresh()->replay_count)->toBe(0)
        ->and($letter->fresh()->replay_requested_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replay_requested')->count())->toBe(1)
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replayed')->count())->toBe(0);

    app()->instance(Dispatcher::class, $originalDispatcher);
    Queue::fake();
    $failedToken = $letter->fresh()->replay_intent_token;
    $letter->fresh()->forceFill(['replay_dispatch_lease_until' => now()->subSecond()])->save();
    $claimed = app(MonitoringDeliveryRecoveryService::class)->recover();

    expect($claimed['replay'])->toBe(1);
    Queue::assertPushed(ReplayMonitoringDeadLetter::class, fn (ReplayMonitoringDeadLetter $job): bool => $job->deadLetterId === $letter->id
        && $job->intentToken !== $failedToken);
});

it('authorises the freshly locked dead letter instead of a stale caller model', function () {
    Queue::fake();
    $allowedSite = Site::factory()->create();
    $deniedSite = Site::factory()->create();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000131');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000131',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
        'site_id' => $deniedSite->id,
    ]);
    $letter->setAttribute('site_id', $allowedSite->id);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->once()->with($actor)->andReturn([$allowedSite->id]);

    expect(fn () => (new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, app(MonitoringEnvelopeConsumer::class)))
        ->replay($actor, $letter, 'Use locked scope'))
        ->toThrow(AuthorizationException::class, 'outside your access scope');
    Queue::assertNothingPushed();
});

it('redispatches one durable replay intent and blocks discard while it is pending', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000141');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000141',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
    ]);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->twice()->with($actor)->andReturn([]);
    $service = new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, app(MonitoringEnvelopeConsumer::class));

    $service->replay($actor, $letter, 'First request');
    $service->replay($actor, $letter, 'Duplicate request');

    expect(AuditLog::where('action', 'monitoring.dead_letter.replay_requested')->count())->toBe(1)
        ->and($letter->fresh()->replay_request_reason)->toBe('First request');
    Queue::assertPushed(ReplayMonitoringDeadLetter::class, 2);
    expect(fn () => $service->discard($actor, $letter, 'Cannot race'))
        ->toThrow(UnexpectedValueException::class, 'pending replay');
});

it('keeps a replay intent recoverable when synchronous handling fails', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000151');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000151',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
    ]);
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new RuntimeException('handler unavailable'));
    $consumer = deliveryConsumer($handler);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->times(2)->with(Mockery::type(User::class))->andReturn([]);
    $service = new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, $consumer);
    $service->replay($actor, $letter, 'Recoverable request');
    $job = Queue::pushed(ReplayMonitoringDeadLetter::class)->first();

    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'handler unavailable')
        ->and($letter->fresh()->replay_requested_at)->not->toBeNull()
        ->and($letter->fresh()->replay_count)->toBe(0)
        ->and(MonitoringInbox::count())->toBe(0)
        ->and((new ReplayMonitoringDeadLetter($letter->id, 'test-token'))->tries)->toBe(5);
});

it('allows an authorised takeover after the persisted replay actor is deleted', function () {
    Queue::fake();
    $firstActor = User::factory()->create();
    $takeoverActor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $firstActor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $takeoverActor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(1, '018f0000-0000-7000-8000-000000000161');
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000161',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
    ]);
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    $consumer = deliveryConsumer($handler);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->times(3)->with(Mockery::type(User::class))->andReturn([]);
    $service = new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, $consumer);

    $service->replay($firstActor, $letter, 'Initial operator request');
    $firstActor->delete();
    $service->replay($takeoverActor, $letter, 'Take over orphaned request');
    $jobs = Queue::pushed(ReplayMonitoringDeadLetter::class)->values();
    expect($jobs[0]->intentToken)->not->toBe($jobs[1]->intentToken);
    $jobs[0]->handle($service);
    expect($letter->fresh()->replay_count)->toBe(0)
        ->and($letter->fresh()->resolved_at)->toBeNull();
    $jobs[1]->handle($service);

    expect($letter->fresh()->replay_count)->toBe(1)
        ->and($letter->fresh()->replay_requested_at)->toBeNull()
        ->and($letter->fresh()->resolved_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replay_requested')->count())->toBe(1)
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replay_taken_over')->count())->toBe(1)
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replayed')->count())->toBe(1);
});

it('recovers a replay after permission revocation through an audited operator takeover', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $device = Device::factory()->create();
    $firstActor = User::factory()->create();
    $takeoverActor = User::factory()->create();
    $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
    $firstActor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $takeoverActor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $encoded = deliveryEnvelope(
        1,
        '018f0000-0000-7000-8000-000000000171',
        payload: ['site_id' => $site->id, 'device_id' => $device->id],
    );
    $letter = MonitoringDeadLetter::create([
        'message_id' => '018f0000-0000-7000-8000-000000000171',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'observation:1',
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encoded,
        'site_id' => $site->id,
    ]);
    $handler = Mockery::mock(RuntimeEnvelopeHandler::class);
    $handler->shouldReceive('handle')->once();
    $consumer = deliveryConsumer($handler);
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('accessibleSiteIds')->times(3)->andReturn([$site->id]);
    $access->shouldReceive('assertCanViewDevice')->times(3);
    $service = new MonitoringReplayService(app(RuntimeEnvelopeCodec::class), $access, $consumer);

    $service->replay($firstActor, $letter, 'Initial request');
    $firstJob = Queue::pushed(ReplayMonitoringDeadLetter::class)->first();
    $firstActor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    expect(fn () => $firstJob->handle($service))->toThrow(AuthorizationException::class);

    $service->replay($takeoverActor, $letter, 'Permission-recovery takeover');
    Queue::pushed(ReplayMonitoringDeadLetter::class)->last()->handle($service);

    expect($letter->fresh()->replay_count)->toBe(1)
        ->and($letter->fresh()->replay_requested_at)->toBeNull()
        ->and(AuditLog::where('action', 'monitoring.dead_letter.replay_taken_over')->count())->toBe(1);
});

it('keeps dead-letter exact evidence identity immutable across model and bulk writes', function () {
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000179',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'immutable:1',
        'reason_code' => 'payload_invalid',
        'reason_message' => 'Envelope payload is invalid.',
        'envelope_bytes' => '{immutable-evidence',
    ];

    expect(fn () => MonitoringDeadLetter::create([
        ...$attributes,
        'evidence_fingerprint' => str_repeat('0', 64),
    ]))->toThrow(UnexpectedValueException::class, 'evidence identity is invalid');

    $letter = MonitoringDeadLetter::create($attributes);
    expect($letter->evidence_fingerprint)->toBe(hash('sha256', $attributes['envelope_bytes']))
        ->and($letter->dedupe_key)->not->toBeNull();

    $normal = $letter->fresh();
    $normal->envelope_bytes = '{changed';
    expect(fn () => $normal->save())->toThrow(UnexpectedValueException::class, 'is immutable');

    $quiet = $letter->fresh();
    $quiet->reason_message = 'Changed evidence explanation.';
    expect(fn () => $quiet->saveQuietly())->toThrow(UnexpectedValueException::class, 'is immutable');

    expect(fn () => MonitoringDeadLetter::query()->whereKey($letter->id)->update([
        'site_id' => 123,
    ]))->toThrow(UnexpectedValueException::class, 'is immutable');

    expect(fn () => MonitoringDeadLetter::query()->insertOrIgnore([[
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-00000000017a',
        'evidence_fingerprint' => str_repeat('f', 64),
    ]]))->toThrow(UnexpectedValueException::class, 'evidence identity is invalid');

    expect(fn () => MonitoringDeadLetter::query()->upsert(
        [$attributes],
        ['dedupe_key'],
        ['envelope_bytes'],
    ))->toThrow(UnexpectedValueException::class, 'is immutable');

    MonitoringDeadLetter::query()->whereKey($letter->id)->update(['resolution_reason' => 'Lifecycle update allowed']);
    expect($letter->fresh()->resolution_reason)->toBe('Lifecycle update allowed');
});

it('backfills exact evidence identities without deleting legacy duplicate dead letters', function () {
    $migration = require database_path('migrations/2026_07_21_100002_add_replay_intent_to_monitoring_dead_letters.php');
    $migration->down();
    $timestamp = now();
    $legacy = [
        'message_id' => '018f0000-0000-7000-8000-000000000181',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 1,
        'idempotency_key' => 'legacy-duplicate',
        'reason_code' => 'invalid_signature',
        'reason_message' => 'Envelope authentication failed.',
        'envelope_bytes' => '{legacy-poison',
        'site_id' => null,
        'replay_count' => 0,
        'last_replayed_at' => null,
        'resolved_at' => null,
        'resolved_by_user_id' => null,
        'resolution_reason' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];

    DB::table('monitoring_dead_letters')->insert([$legacy, $legacy]);
    $migration->up();

    $rows = DB::table('monitoring_dead_letters')->orderBy('id')->get();

    expect(Schema::hasColumns('monitoring_dead_letters', ['evidence_fingerprint', 'dedupe_key']))->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('evidence_fingerprint')->unique()->values()->all())
        ->toBe([hash('sha256', $legacy['envelope_bytes'])])
        ->and($rows->pluck('dedupe_key')->filter()->unique()->count())->toBe(2);

    $duplicate = (array) $rows->first();
    unset($duplicate['id']);

    expect(fn () => DB::table('monitoring_dead_letters')->insert($duplicate))->toThrow(QueryException::class);
});
