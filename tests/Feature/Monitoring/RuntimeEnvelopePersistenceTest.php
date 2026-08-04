<?php

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\MonitoringConsumerCheckpoint;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringInbox;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Models\Site;
use App\Support\LegacyStorageContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $appEnvironment = getenv('APP_ENV');
    $databaseConnection = getenv('DB_CONNECTION');
    $databasePath = getenv('DB_DATABASE');

    // Local harness escape hatch only. Never enable for CI or a full test suite.
    if ($appEnvironment !== 'testing'
        || $databaseConnection !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)
    ) {
        throw new RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

beforeEach(function () {
    config()->set('monitoring.signing', [
        'active_key_id' => 'runtime-test-key',
        'keys' => [
            'runtime-test-key' => base64_encode(str_repeat("\x2a", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);
});

it('provides application-wide durable delivery controls', function () {
    $deadLetterIndexes = collect(Schema::getIndexes('monitoring_dead_letters'))->keyBy('name');

    expect(Schema::hasColumns('monitoring_outbox', [
        'message_id',
        'stream',
        'source',
        'sequence',
        'idempotency_key',
        'envelope_bytes',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_inbox', [
            'message_id',
            'consumer',
            'source',
            'sequence',
            'idempotency_key',
            'payload_hash',
            'envelope_bytes',
            'processed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_consumer_checkpoints', [
            'consumer',
            'source',
            'last_sequence',
            'gap_from',
            'gap_to',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_dead_letters', [
            'message_id',
            'consumer',
            'source',
            'sequence',
            'idempotency_key',
            'reason_code',
            'reason_message',
            'envelope_bytes',
            'site_id',
            'replay_count',
            'last_replayed_at',
            'resolved_at',
            'resolved_by_user_id',
            'resolution_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('monitoring_outbox', 'envelope'))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_inbox', 'envelope'))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_dead_letters', 'envelope'))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_outbox', LegacyStorageContext::column()))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_inbox', LegacyStorageContext::column()))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_consumer_checkpoints', LegacyStorageContext::column()))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_dead_letters', LegacyStorageContext::column()))->toBeFalse()
        ->and($deadLetterIndexes->get('monitoring_dead_letters_consumer_resolved_idx')['columns'])->toBe(['consumer', 'resolved_at'])
        ->and($deadLetterIndexes->get('monitoring_dead_letters_created_idx')['columns'])->toBe(['created_at'])
        ->and($deadLetterIndexes->get('monitoring_dead_letters_site_id_index')['columns'])->toBe(['site_id']);
});

it('round trips and persists a signed v2 envelope without signing material', function () {
    $envelope = RuntimeEnvelope::new(
        type: RuntimeMessageType::Observation,
        source: 'central:checks',
        sequence: 7,
        idempotencyKey: 'monitor:9:sample:7',
        payload: ['monitor_id' => 9, 'state' => 'healthy'],
    );
    $codec = app(RuntimeEnvelopeCodec::class);
    $encoded = $codec->encode($envelope);
    $decoded = $codec->decode($encoded);

    $outbox = MonitoringOutbox::create([
        'message_id' => $decoded->messageId,
        'stream' => 'checks',
        'source' => $decoded->source,
        'sequence' => $decoded->sequence,
        'idempotency_key' => $decoded->idempotencyKey,
        'envelope_bytes' => $encoded,
        'available_at' => now(),
    ]);
    $inbox = MonitoringInbox::create([
        'message_id' => $decoded->messageId,
        'consumer' => 'observation-projector',
        'source' => $decoded->source,
        'sequence' => $decoded->sequence,
        'idempotency_key' => $decoded->idempotencyKey,
        'payload_hash' => hash('sha256', $encoded),
        'envelope_bytes' => $encoded,
    ]);
    $checkpoint = MonitoringConsumerCheckpoint::create([
        'consumer' => 'observation-projector',
        'source' => $decoded->source,
        'last_sequence' => 6,
        'gap_from' => 7,
        'gap_to' => 8,
    ]);
    $deadLetter = MonitoringDeadLetter::create([
        'message_id' => $decoded->messageId,
        'consumer' => 'observation-projector',
        'source' => $decoded->source,
        'sequence' => $decoded->sequence,
        'idempotency_key' => $decoded->idempotencyKey,
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 7.',
        'envelope_bytes' => $encoded,
    ]);

    expect($decoded->schemaVersion)->toBe(2)
        ->and($decoded->payloadVersion)->toBe(2)
        ->and($decoded->sequence)->toBe(7)
        ->and($decoded->traceId)->not->toBeEmpty()
        ->and($decoded->keyId)->toBe('runtime-test-key')
        ->and($decoded->signature)->not->toBeEmpty()
        ->and($outbox->fresh()->envelope_bytes)->toBe($encoded)
        ->and($inbox->fresh()->envelope_bytes)->toBe($encoded)
        ->and($inbox->fresh()->payload_hash)->toBe(hash('sha256', $inbox->fresh()->envelope_bytes))
        ->and($checkpoint->fresh()->gap_from)->toBe(7)
        ->and($deadLetter->fresh()->replay_count)->toBe(0)
        ->and($deadLetter->fresh()->envelope_bytes)->toBe($encoded)
        ->and(implode('', [
            $outbox->fresh()->envelope_bytes,
            $inbox->fresh()->envelope_bytes,
            $deadLetter->fresh()->envelope_bytes,
        ]))->not->toContain(config('monitoring.signing.keys.runtime-test-key'));
});

it('keeps dead letter site context trusted and outside untrusted envelope payload', function () {
    $trustedSite = Site::factory()->create();
    $untrustedBytes = '{"payload":{"site_id":999999}}';
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000031',
        'consumer' => 'privileged-dead-letter-review',
        'source' => 'collector:remote-a',
        'sequence' => 31,
        'idempotency_key' => 'invalid:31',
        'reason_code' => 'invalid_envelope',
        'reason_message' => 'Envelope failed validation before site routing.',
        'envelope_bytes' => $untrustedBytes,
    ];

    $unscoped = MonitoringDeadLetter::create($attributes);
    $scoped = MonitoringDeadLetter::create([
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-000000000032',
        'sequence' => 32,
        'idempotency_key' => 'invalid:32',
        'site_id' => $trustedSite->id,
    ]);

    expect($unscoped->fresh()->site_id)->toBeNull()
        ->and($unscoped->fresh()->envelope_bytes)->toBe($untrustedBytes)
        ->and($scoped->fresh()->site_id)->toBe($trustedSite->id)
        ->and(data_get(json_decode($scoped->fresh()->envelope_bytes, true), 'payload.site_id'))
        ->not->toBe($scoped->fresh()->site_id);
});

it('requires inbox payload integrity to match the exact envelope bytes at creation', function () {
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000041',
        'consumer' => 'integrity-projector',
        'source' => 'central:checks',
        'sequence' => 41,
        'idempotency_key' => 'integrity:41',
        'payload_hash' => str_repeat('0', 64),
        'envelope_bytes' => '{"schema_version":1}',
    ];

    expect(fn () => MonitoringInbox::create($attributes))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox payload hash does not match envelope bytes.');

    $quietInbox = new MonitoringInbox($attributes);
    expect(fn () => $quietInbox->saveQuietly())
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox payload hash does not match envelope bytes.');
});

it('makes inbox delivery identity and evidence immutable after creation', function () {
    $bytes = '{"schema_version":1}';
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000041',
        'consumer' => 'integrity-projector',
        'source' => 'central:checks',
        'sequence' => 41,
        'idempotency_key' => 'integrity:41',
        'payload_hash' => hash('sha256', $bytes),
        'envelope_bytes' => $bytes,
    ];
    $changes = [
        'message_id' => '018f0000-0000-7000-8000-000000000042',
        'consumer' => 'replacement-projector',
        'source' => 'collector:replacement',
        'sequence' => 42,
        'idempotency_key' => 'integrity:42',
        'envelope_bytes' => '{"schema_version":1,"sequence":42}',
        'payload_hash' => str_repeat('f', 64),
    ];

    foreach ($changes as $attribute => $replacement) {
        $inbox = MonitoringInbox::create($attributes);

        expect(function () use ($inbox, $attribute, $replacement): void {
            $inbox->{$attribute} = $replacement;
            $inbox->save();
        })
            ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');

        $inbox->delete();
    }

    $inbox = MonitoringInbox::create($attributes);
    expect(function () use ($inbox, $changes): void {
        $inbox->envelope_bytes = $changes['envelope_bytes'];
        $inbox->payload_hash = hash('sha256', $changes['envelope_bytes']);
        $inbox->save();
    })
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');
    $inbox->delete();

    $inbox = MonitoringInbox::create($attributes);
    expect(fn () => $inbox->updateQuietly(['source' => 'collector:quiet-rewrite']))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');
    $inbox->delete();

    $inbox = MonitoringInbox::create($attributes);
    expect(fn () => MonitoringInbox::query()->whereKey($inbox->id)->update([
        'envelope_bytes' => $changes['envelope_bytes'],
        'payload_hash' => hash('sha256', $changes['envelope_bytes']),
    ]))->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');
});

it('allows inbox lifecycle updates and preserves first-or-create existing-row semantics', function () {
    $bytes = '{"schema_version":1}';
    $inbox = MonitoringInbox::create([
        'message_id' => '018f0000-0000-7000-8000-000000000041',
        'consumer' => 'integrity-projector',
        'source' => 'central:checks',
        'sequence' => 41,
        'idempotency_key' => 'integrity:41',
        'payload_hash' => hash('sha256', $bytes),
        'envelope_bytes' => $bytes,
    ]);
    $processedAt = now()->startOfSecond();
    $inbox->processed_at = $processedAt;
    $inbox->save();

    $quietProcessedAt = $processedAt->copy()->addMinute();
    $inbox->updateQuietly(['processed_at' => $quietProcessedAt]);

    $bulkProcessedAt = $processedAt->copy()->addMinutes(2);
    MonitoringInbox::query()->whereKey($inbox->id)->update(['processed_at' => $bulkProcessedAt]);

    $duplicate = MonitoringInbox::firstOrCreate([
        'consumer' => $inbox->consumer,
        'message_id' => $inbox->message_id,
    ], [
        'source' => 'unreachable:create-values',
        'sequence' => 999,
        'idempotency_key' => 'unreachable:create-values',
        'payload_hash' => hash('sha256', '{"different":true}'),
        'envelope_bytes' => '{"different":true}',
    ]);

    expect($inbox->fresh()->processed_at->equalTo($bulkProcessedAt))->toBeTrue()
        ->and($inbox->fresh()->payload_hash)->toBe(hash('sha256', $inbox->fresh()->envelope_bytes))
        ->and($duplicate->is($inbox))->toBeTrue()
        ->and($duplicate->envelope_bytes)->toBe($inbox->fresh()->envelope_bytes);
});

it('guards normal Eloquent bulk writes against inbox evidence bypasses', function () {
    $bytes = '{"schema_version":1}';
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000051',
        'consumer' => 'bulk-guard-projector',
        'source' => 'central:checks',
        'sequence' => 51,
        'idempotency_key' => 'bulk-guard:51',
        'payload_hash' => hash('sha256', $bytes),
        'envelope_bytes' => $bytes,
    ];
    $inbox = MonitoringInbox::create($attributes);
    $invalid = [
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-000000000052',
        'sequence' => 52,
        'idempotency_key' => 'bulk-guard:52',
        'payload_hash' => str_repeat('0', 64),
    ];

    expect(fn () => $inbox->increment('sequence'))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');
    expect(fn () => MonitoringInbox::query()->whereKey($inbox->id)->update(['SEQUENCE' => 52]))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');
    expect(fn () => MonitoringInbox::query()->whereKey($inbox->id)->touch('SOURCE'))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');
    DB::table('monitoring_inbox')->where('id', $inbox->id)->update(['updated_at' => now()->subMinute()]);
    expect(MonitoringInbox::query()->whereKey($inbox->id)->touch())->toBe(1);

    foreach (['insert', 'insertOrIgnore', 'insertOrIgnoreReturning', 'insertGetId'] as $method) {
        expect(fn () => MonitoringInbox::query()->{$method}($invalid))
            ->toThrow(UnexpectedValueException::class, 'Monitoring inbox payload hash does not match envelope bytes.');
    }

    expect(fn () => MonitoringInbox::query()->upsert([
        ...$attributes,
        'envelope_bytes' => '{"schema_version":1,"replacement":true}',
        'payload_hash' => hash('sha256', '{"schema_version":1,"replacement":true}'),
    ], ['consumer', 'message_id'], ['envelope_bytes', 'payload_hash']))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.')
        ->and(fn () => MonitoringInbox::query()->upsert(
            $attributes,
            ['consumer', 'message_id'],
            ['SEQUENCE' => DB::raw('sequence + 1')],
        ))->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.')
        ->and(fn () => MonitoringInbox::query()->upsert($attributes, ['consumer', 'message_id']))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.')
        ->and(fn () => MonitoringInbox::query()->updateOrInsert(
            ['consumer' => $inbox->consumer, 'message_id' => $inbox->message_id],
            ['envelope_bytes' => $bytes, 'payload_hash' => hash('sha256', $bytes)],
        ))->toThrow(UnexpectedValueException::class, 'Monitoring inbox delivery identity and evidence are immutable.');

    $sourceQuery = MonitoringOutbox::query()->select('message_id');
    expect(fn () => MonitoringInbox::query()->insertUsing(['message_id'], $sourceQuery))
        ->toThrow(UnexpectedValueException::class, 'Monitoring inbox insert-from-query is not permitted.');
});

it('canonicalises nested payload keys before signing', function () {
    $occurredAt = CarbonImmutable::parse('2026-07-21T01:02:03.456789Z');
    $first = new RuntimeEnvelope(
        schemaVersion: 1,
        messageId: '018f0000-0000-7000-8000-000000000001',
        type: RuntimeMessageType::Observation,
        source: 'central:checks',
        sequence: 7,
        occurredAt: $occurredAt,
        ingestedAt: $occurredAt,
        idempotencyKey: 'monitor:9:sample:7',
        traceId: '018f0000-0000-7000-8000-000000000002',
        payload: ['z' => ['b' => 2, 'a' => 1], 'a' => [['d' => 4, 'c' => 3]]],
    );
    $second = new RuntimeEnvelope(
        schemaVersion: 1,
        messageId: $first->messageId,
        type: $first->type,
        source: $first->source,
        sequence: $first->sequence,
        occurredAt: $first->occurredAt,
        ingestedAt: $first->ingestedAt,
        idempotencyKey: $first->idempotencyKey,
        traceId: $first->traceId,
        payload: ['a' => [['c' => 3, 'd' => 4]], 'z' => ['a' => 1, 'b' => 2]],
    );

    expect(app(RuntimeEnvelopeCodec::class)->encode($first))
        ->toBe(app(RuntimeEnvelopeCodec::class)->encode($second));
});

it('accepts the previous v1 envelope while emitting explicit v2 payload versions', function () {
    $codec = app(RuntimeEnvelopeCodec::class);
    $at = CarbonImmutable::parse('2026-07-21T01:02:03.456789Z');
    $legacy = new RuntimeEnvelope(
        schemaVersion: 1,
        messageId: '018f0000-0000-7000-8000-000000000101',
        type: RuntimeMessageType::Observation,
        source: 'collector:legacy-runtime',
        sequence: 1,
        occurredAt: $at,
        ingestedAt: $at,
        idempotencyKey: 'legacy-observation:1',
        traceId: '018f0000-0000-7000-8000-000000000102',
        payload: ['monitor_id' => 9, 'state' => 'healthy'],
    );

    $legacyDocument = json_decode($codec->encode($legacy), true, flags: JSON_THROW_ON_ERROR);
    $current = $codec->decode($codec->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Observation,
        'central:checks',
        2,
        'current-observation:2',
        ['monitor_id' => 9, 'state' => 'healthy'],
    )));

    expect($legacyDocument)->not->toHaveKey('payload_version')
        ->and($codec->decode($codec->encode($legacy))->schemaVersion)->toBe(1)
        ->and($codec->decode($codec->encode($legacy))->payloadVersion)->toBe(1)
        ->and($current->schemaVersion)->toBe(2)
        ->and($current->payloadVersion)->toBe(2);
});

it('accepts only the exact canonical transport bytes', function () {
    $codec = app(RuntimeEnvelopeCodec::class);
    $encoded = $codec->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Observation,
        'central:checks',
        7,
        'monitor:9:sample:7',
        ['monitor_id' => 9, 'state' => 'healthy'],
    ));
    $document = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    $pretty = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $reordered = json_encode(array_reverse($document, true), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $duplicate = preg_replace('/^\{/', '{"schema_version":1,', $encoded, 1);

    foreach ([$pretty, $reordered, $duplicate] as $nonCanonical) {
        expect(fn () => $codec->decode($nonCanonical))
            ->toThrow(UnexpectedValueException::class, 'Monitoring envelope JSON is not canonical.');
    }
});

it('supports exact dotted key ids during signing key rotation', function () {
    config()->set('monitoring.signing', [
        'active_key_id' => 'key.v1',
        'keys' => [
            'key.v0' => base64_encode(str_repeat("\x19", SODIUM_CRYPTO_AUTH_KEYBYTES)),
            'key.v1' => base64_encode(str_repeat("\x20", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);

    $codec = app(RuntimeEnvelopeCodec::class);
    $decoded = $codec->decode($codec->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Event,
        'central:events',
        8,
        'event:8',
        ['state' => 'warning'],
    )));

    expect($decoded->keyId)->toBe('key.v1');
});

it('canonically round trips nested zero and representative finite floats without mutating the caller', function () {
    $payload = [
        'negative_zero' => -0.0,
        'nested' => [
            'positive_zero' => 0.0,
            'positive_fraction' => 1.5,
            'negative_fraction' => -2.75,
            'small_exponent' => 1.25e-12,
            'large_exponent' => 9.5e20,
        ],
    ];
    $envelope = RuntimeEnvelope::new(
        RuntimeMessageType::Observation,
        'central:checks',
        9,
        'monitor:9:sample:9',
        $payload,
    );
    $codec = app(RuntimeEnvelopeCodec::class);
    $decoded = $codec->decode($codec->encode($envelope));

    expect($decoded->payload['negative_zero'])->toBe(0)
        ->and($decoded->payload['nested']['positive_zero'])->toBe(0)
        ->and($decoded->payload['nested']['positive_fraction'])->toBe(1.5)
        ->and($decoded->payload['nested']['negative_fraction'])->toBe(-2.75)
        ->and($decoded->payload['nested']['small_exponent'])->toBe(1.25e-12)
        ->and($decoded->payload['nested']['large_exponent'])->toBe(9.5e20)
        ->and(is_float($envelope->payload['negative_zero']))->toBeTrue()
        ->and(is_float($envelope->payload['nested']['positive_zero']))->toBeTrue();
});

it('rejects an encoded envelope before parsing when its byte cap is exceeded', function () {
    expect(fn () => app(RuntimeEnvelopeCodec::class)->decode(
        str_repeat('x', RuntimeEnvelopeCodec::MAX_ENCODED_BYTES + 1),
    ))->toThrow(UnexpectedValueException::class, 'Monitoring envelope exceeds the maximum encoded size.');
});

it('rejects an envelope that crosses the byte cap while encoding', function () {
    expect(fn () => app(RuntimeEnvelopeCodec::class)->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Observation,
        'central:checks',
        9,
        'monitor:9:sample:9',
        array_fill(0, 5, str_repeat('x', RuntimeEnvelopeCodec::MAX_STRING_BYTES)),
    )))->toThrow(UnexpectedValueException::class, 'Monitoring envelope exceeds the maximum encoded size.');
});

it('rejects JSON that exceeds the decode depth before structural traversal', function () {
    $encoded = str_repeat('[', RuntimeEnvelopeCodec::JSON_DECODE_DEPTH + 1)
        .str_repeat(']', RuntimeEnvelopeCodec::JSON_DECODE_DEPTH + 1);

    expect(fn () => app(RuntimeEnvelopeCodec::class)->decode($encoded))
        ->toThrow(UnexpectedValueException::class, 'Monitoring envelope exceeds the maximum JSON depth.');
});

it('bounds payload depth breadth node count and string or key bytes', function (Closure $payload, string $message) {
    expect(fn () => app(RuntimeEnvelopeCodec::class)->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Observation,
        'central:checks',
        9,
        'monitor:9:sample:9',
        $payload(),
    )))->toThrow(UnexpectedValueException::class, $message);
})->with([
    'depth' => [function (): array {
        $payload = [];

        for ($depth = 0; $depth <= RuntimeEnvelopeCodec::MAX_PAYLOAD_DEPTH; $depth++) {
            $payload = ['nested' => $payload];
        }

        return $payload;
    }, 'Monitoring envelope payload exceeds the maximum depth.'],
    'breadth' => [
        fn (): array => array_fill(0, RuntimeEnvelopeCodec::MAX_CONTAINER_ITEMS + 1, true),
        'Monitoring envelope payload exceeds the maximum container breadth.',
    ],
    'nodes' => [function (): array {
        return [
            'groups' => array_fill(
                0,
                100,
                array_fill(0, (int) ceil(RuntimeEnvelopeCodec::MAX_TOTAL_NODES / 100), true),
            ),
        ];
    }, 'Monitoring envelope payload exceeds the maximum node count.'],
    'string bytes' => [
        fn (): array => ['value' => str_repeat('x', RuntimeEnvelopeCodec::MAX_STRING_BYTES + 1)],
        'Monitoring envelope payload exceeds the maximum string or key size.',
    ],
    'key bytes' => [
        fn (): array => [str_repeat('k', RuntimeEnvelopeCodec::MAX_KEY_BYTES + 1) => true],
        'Monitoring envelope payload exceeds the maximum string or key size.',
    ],
]);

it('applies payload bounds before canonicalising or authenticating decoded data', function () {
    $document = [
        'idempotency_key' => 'monitor:9:sample:9',
        'ingested_at' => '2026-07-21T01:02:03.456789Z',
        'key_id' => 'runtime-test-key',
        'message_id' => '018f0000-0000-7000-8000-000000000001',
        'occurred_at' => '2026-07-21T01:02:03.456789Z',
        'payload' => array_fill(0, RuntimeEnvelopeCodec::MAX_CONTAINER_ITEMS + 1, true),
        'schema_version' => 1,
        'sequence' => 9,
        'signature' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_AUTH_BYTES)),
        'source' => 'central:checks',
        'trace_id' => '018f0000-0000-7000-8000-000000000002',
        'type' => 'observation',
    ];

    expect(fn () => app(RuntimeEnvelopeCodec::class)->decode(
        json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ))->toThrow(
        UnexpectedValueException::class,
        'Monitoring envelope payload exceeds the maximum container breadth.',
    );
});

it('rejects nested objects resources and closures instead of coercing them', function () {
    $resource = fopen('php://memory', 'rb');
    $values = [
        (object) ['state' => 'healthy'],
        static fn (): null => null,
        $resource,
    ];

    try {
        foreach ($values as $value) {
            expect(fn () => app(RuntimeEnvelopeCodec::class)->encode(RuntimeEnvelope::new(
                RuntimeMessageType::Observation,
                'central:checks',
                10,
                'monitor:9:sample:10',
                ['value' => $value],
            )))->toThrow(
                UnexpectedValueException::class,
                'Monitoring envelope payload contains an unsupported value.',
            );
        }
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});

it('rejects non-finite floating point payload values', function () {
    foreach ([INF, -INF, NAN] as $value) {
        expect(fn () => app(RuntimeEnvelopeCodec::class)->encode(RuntimeEnvelope::new(
            RuntimeMessageType::Observation,
            'central:checks',
            10,
            'monitor:9:sample:10',
            ['value' => $value],
        )))->toThrow(
            UnexpectedValueException::class,
            'Monitoring envelope payload contains an unsupported value.',
        );
    }
});

it('rejects malformed unsupported or unauthenticated envelopes with specific reasons', function (Closure $mutate, string $message) {
    $codec = app(RuntimeEnvelopeCodec::class);
    $encoded = $codec->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Event,
        'central:events',
        3,
        'event:3',
        ['severity' => 'warning'],
    ));
    $document = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    $mutate($document);

    expect(fn () => $codec->decode(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
        ->toThrow(UnexpectedValueException::class, $message);
})->with([
    'missing field' => [function (array &$document): void {
        unset($document['trace_id']);
    }, 'Monitoring envelope fields are invalid.'],
    'unexpected field' => [fn (array &$document) => $document['extra'] = true, 'Monitoring envelope fields are invalid.'],
    'unknown version' => [fn (array &$document) => $document['schema_version'] = 99, 'Monitoring envelope version is unsupported.'],
    'unknown key' => [fn (array &$document) => $document['key_id'] = 'retired-key', 'Monitoring envelope signing key is unknown.'],
    'invalid message id' => [fn (array &$document) => $document['message_id'] = 'not-a-uuid', 'Monitoring envelope fields are invalid.'],
    'tampered payload' => [fn (array &$document) => $document['payload']['severity'] = 'critical', 'Monitoring envelope signature is invalid.'],
    'invalid signature encoding' => [fn (array &$document) => $document['signature'] = '***', 'Monitoring envelope signature is invalid.'],
    'invalid timestamp' => [fn (array &$document) => $document['occurred_at'] = 'yesterday', 'Monitoring envelope timestamp is invalid.'],
]);

it('enforces single-application delivery identities by source and consumer', function () {
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000011',
        'stream' => 'checks',
        'source' => 'central:checks',
        'sequence' => 11,
        'idempotency_key' => 'monitor:9:sample:11',
        'envelope_bytes' => '{"schema_version":1}',
        'available_at' => now(),
    ];

    MonitoringOutbox::create($attributes);

    expect(fn () => MonitoringOutbox::create([
        ...$attributes,
        'source' => 'collector:message-collision',
        'sequence' => 99,
        'idempotency_key' => 'monitor:other:sample:99',
    ]))->toThrow(QueryException::class);

    expect(fn () => MonitoringOutbox::create([
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-000000000012',
        'idempotency_key' => 'monitor:9:sample:12',
    ]))->toThrow(QueryException::class);

    expect(fn () => MonitoringOutbox::create([
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-000000000013',
        'sequence' => 12,
    ]))->toThrow(QueryException::class);

    MonitoringOutbox::create([
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-000000000014',
        'source' => 'collector:remote-a',
    ]);

    $inbox = [
        'message_id' => '018f0000-0000-7000-8000-000000000021',
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'sequence' => 11,
        'idempotency_key' => 'monitor:9:sample:11',
        'payload_hash' => hash('sha256', '{"schema_version":1}'),
        'envelope_bytes' => '{"schema_version":1}',
    ];
    MonitoringInbox::create($inbox);

    expect(fn () => MonitoringInbox::create([
        ...$inbox,
        'source' => 'collector:remote-a',
        'sequence' => 12,
        'idempotency_key' => 'monitor:9:sample:12',
    ]))->toThrow(QueryException::class);

    expect(fn () => MonitoringInbox::create([
        ...$inbox,
        'message_id' => '018f0000-0000-7000-8000-000000000022',
        'sequence' => 12,
    ]))->toThrow(QueryException::class);

    MonitoringConsumerCheckpoint::create([
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'last_sequence' => 1,
    ]);

    expect(fn () => MonitoringConsumerCheckpoint::create([
        'consumer' => 'observation-projector',
        'source' => 'central:checks',
        'last_sequence' => 2,
    ]))->toThrow(QueryException::class)
        ->and(DB::table('monitoring_outbox')->count())->toBe(2);
});
