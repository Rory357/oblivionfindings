<?php

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\MonitoringConsumerCheckpoint;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringInbox;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
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

it('provides tenant scoped durable delivery controls', function () {
    expect(Schema::hasColumns('monitoring_outbox', [
        'message_id',
        'tenant_id',
        'stream',
        'source',
        'sequence',
        'idempotency_key',
        'envelope',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_inbox', [
            'message_id',
            'tenant_id',
            'consumer',
            'source',
            'sequence',
            'idempotency_key',
            'payload_hash',
            'envelope',
            'processed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_consumer_checkpoints', [
            'tenant_id',
            'consumer',
            'source',
            'last_sequence',
            'gap_from',
            'gap_to',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_dead_letters', [
            'message_id',
            'tenant_id',
            'consumer',
            'source',
            'sequence',
            'idempotency_key',
            'reason_code',
            'reason_message',
            'envelope',
            'replay_count',
            'last_replayed_at',
            'resolved_at',
            'resolved_by_user_id',
            'resolution_reason',
        ]))->toBeTrue();
});

it('round trips and persists a signed v1 envelope without signing material', function () {
    $envelope = RuntimeEnvelope::new(
        type: RuntimeMessageType::Observation,
        tenantId: 42,
        source: 'central:checks',
        sequence: 7,
        idempotencyKey: 'monitor:9:sample:7',
        payload: ['monitor_id' => 9, 'state' => 'healthy'],
    );
    $codec = app(RuntimeEnvelopeCodec::class);
    $encoded = $codec->encode($envelope);
    $decoded = $codec->decode($encoded);
    $originalEnvelope = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

    $outbox = MonitoringOutbox::create([
        'message_id' => $decoded->messageId,
        'tenant_id' => $decoded->tenantId,
        'stream' => 'checks',
        'source' => $decoded->source,
        'sequence' => $decoded->sequence,
        'idempotency_key' => $decoded->idempotencyKey,
        'envelope' => $originalEnvelope,
        'available_at' => now(),
    ]);
    $inbox = MonitoringInbox::create([
        'message_id' => $decoded->messageId,
        'tenant_id' => $decoded->tenantId,
        'consumer' => 'observation-projector',
        'source' => $decoded->source,
        'sequence' => $decoded->sequence,
        'idempotency_key' => $decoded->idempotencyKey,
        'payload_hash' => hash('sha256', $encoded),
        'envelope' => $originalEnvelope,
    ]);
    $checkpoint = MonitoringConsumerCheckpoint::create([
        'tenant_id' => $decoded->tenantId,
        'consumer' => 'observation-projector',
        'source' => $decoded->source,
        'last_sequence' => 6,
        'gap_from' => 7,
        'gap_to' => 8,
    ]);
    $deadLetter = MonitoringDeadLetter::create([
        'message_id' => $decoded->messageId,
        'tenant_id' => $decoded->tenantId,
        'consumer' => 'observation-projector',
        'source' => $decoded->source,
        'sequence' => $decoded->sequence,
        'idempotency_key' => $decoded->idempotencyKey,
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 7.',
        'envelope' => $originalEnvelope,
    ]);

    expect($decoded->schemaVersion)->toBe(1)
        ->and($decoded->tenantId)->toBe(42)
        ->and($decoded->sequence)->toBe(7)
        ->and($decoded->traceId)->not->toBeEmpty()
        ->and($decoded->keyId)->toBe('runtime-test-key')
        ->and($decoded->signature)->not->toBeEmpty()
        ->and($outbox->fresh()->envelope)->toBe($originalEnvelope)
        ->and($inbox->fresh()->envelope)->toBe($originalEnvelope)
        ->and($checkpoint->fresh()->gap_from)->toBe(7)
        ->and($deadLetter->fresh()->replay_count)->toBe(0)
        ->and(json_encode([
            $outbox->fresh()->envelope,
            $inbox->fresh()->envelope,
            $deadLetter->fresh()->envelope,
        ], JSON_THROW_ON_ERROR))->not->toContain(config('monitoring.signing.keys.runtime-test-key'));
});

it('canonicalises nested payload keys before signing', function () {
    $occurredAt = CarbonImmutable::parse('2026-07-21T01:02:03.456789Z');
    $first = new RuntimeEnvelope(
        schemaVersion: 1,
        messageId: '018f0000-0000-7000-8000-000000000001',
        type: RuntimeMessageType::Observation,
        tenantId: 42,
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
        tenantId: $first->tenantId,
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

it('accepts only the exact canonical transport bytes', function () {
    $codec = app(RuntimeEnvelopeCodec::class);
    $encoded = $codec->encode(RuntimeEnvelope::new(
        RuntimeMessageType::Observation,
        42,
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
        42,
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
        42,
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
        42,
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
        42,
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
        'tenant_id' => 42,
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
                42,
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
            42,
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
        42,
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
    'unknown version' => [fn (array &$document) => $document['schema_version'] = 2, 'Monitoring envelope version is unsupported.'],
    'unknown key' => [fn (array &$document) => $document['key_id'] = 'retired-key', 'Monitoring envelope signing key is unknown.'],
    'invalid message id' => [fn (array &$document) => $document['message_id'] = 'not-a-uuid', 'Monitoring envelope fields are invalid.'],
    'tampered payload' => [fn (array &$document) => $document['payload']['severity'] = 'critical', 'Monitoring envelope signature is invalid.'],
    'invalid signature encoding' => [fn (array &$document) => $document['signature'] = '***', 'Monitoring envelope signature is invalid.'],
    'invalid timestamp' => [fn (array &$document) => $document['occurred_at'] = 'yesterday', 'Monitoring envelope timestamp is invalid.'],
    'invalid tenant' => [fn (array &$document) => $document['tenant_id'] = 0, 'Monitoring envelope tenant is invalid.'],
]);

it('enforces delivery identities within each tenant', function () {
    $attributes = [
        'message_id' => '018f0000-0000-7000-8000-000000000011',
        'stream' => 'checks',
        'source' => 'central:checks',
        'sequence' => 11,
        'idempotency_key' => 'monitor:9:sample:11',
        'envelope' => ['schema_version' => 1],
        'available_at' => now(),
    ];

    MonitoringOutbox::create(['tenant_id' => 42, ...$attributes]);
    MonitoringOutbox::create(['tenant_id' => 77, ...$attributes]);

    expect(fn () => MonitoringOutbox::create([
        'tenant_id' => 42,
        ...$attributes,
        'message_id' => '018f0000-0000-7000-8000-000000000012',
    ]))->toThrow(QueryException::class)
        ->and(DB::table('monitoring_outbox')->count())->toBe(2);
});
