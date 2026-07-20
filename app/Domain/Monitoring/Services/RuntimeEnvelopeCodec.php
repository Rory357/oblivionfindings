<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\EnvelopeSigner;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use JsonException;
use UnexpectedValueException;

final class RuntimeEnvelopeCodec
{
    /**
     * Runtime envelopes are deliberately small. Large configuration and evidence
     * snapshots travel as governed object-store references, never inline payloads.
     */
    public const int MAX_ENCODED_BYTES = 262_144;

    public const int JSON_DECODE_DEPTH = 32;

    public const int MAX_PAYLOAD_DEPTH = 16;

    public const int MAX_TOTAL_NODES = 10_000;

    public const int MAX_CONTAINER_ITEMS = 1_000;

    public const int MAX_STRING_BYTES = 65_536;

    public const int MAX_KEY_BYTES = 128;

    private const array REQUIRED_FIELDS = [
        'schema_version',
        'message_id',
        'type',
        'source',
        'sequence',
        'occurred_at',
        'ingested_at',
        'idempotency_key',
        'trace_id',
        'payload',
        'key_id',
        'signature',
    ];

    public function __construct(private readonly EnvelopeSigner $signer) {}

    public function encode(RuntimeEnvelope $envelope): string
    {
        $this->validatePayload($envelope->payload);

        $keyId = $this->signer->activeKeyId();
        $document = $this->document($envelope, $keyId);
        $this->validateUnsignedDocument($document);
        $signature = $this->signer->sign($keyId, $this->json($this->canonicalise($document)));
        $document['signature'] = base64_encode($signature);

        $encoded = $this->json($this->canonicalise($document));
        $this->validateEncodedSize($encoded);

        return $encoded;
    }

    public function decode(string $encoded): RuntimeEnvelope
    {
        $this->validateEncodedSize($encoded);

        try {
            $document = json_decode(
                $encoded,
                true,
                self::JSON_DECODE_DEPTH,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new UnexpectedValueException(
                    'Monitoring envelope exceeds the maximum JSON depth.',
                    previous: $exception,
                );
            }

            throw new UnexpectedValueException('Monitoring envelope JSON is invalid.', previous: $exception);
        }

        if (! is_array($document) || array_is_list($document)) {
            throw new UnexpectedValueException('Monitoring envelope fields are invalid.');
        }

        $this->validateFieldSet($document);
        $this->validateUnsignedDocument($document);
        $this->validatePayload($document['payload']);

        if (! hash_equals($this->json($this->canonicalise($document)), $encoded)) {
            throw new UnexpectedValueException('Monitoring envelope JSON is not canonical.');
        }

        $signature = base64_decode($document['signature'], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_AUTH_BYTES) {
            throw new UnexpectedValueException('Monitoring envelope signature is invalid.');
        }

        $unsigned = $document;
        unset($unsigned['signature']);

        if (! $this->signer->verify($document['key_id'], $this->json($this->canonicalise($unsigned)), $signature)) {
            throw new UnexpectedValueException('Monitoring envelope signature is invalid.');
        }

        return new RuntimeEnvelope(
            schemaVersion: $document['schema_version'],
            messageId: $document['message_id'],
            type: RuntimeMessageType::from($document['type']),
            source: $document['source'],
            sequence: $document['sequence'],
            occurredAt: $this->timestamp($document['occurred_at']),
            ingestedAt: $this->timestamp($document['ingested_at']),
            idempotencyKey: $document['idempotency_key'],
            traceId: $document['trace_id'],
            payload: $document['payload'],
            keyId: $document['key_id'],
            signature: $document['signature'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(RuntimeEnvelope $envelope, string $keyId): array
    {
        return [
            'schema_version' => $envelope->schemaVersion,
            'message_id' => $envelope->messageId,
            'type' => $envelope->type->value,
            'source' => $envelope->source,
            'sequence' => $envelope->sequence,
            'occurred_at' => $this->formatTimestamp($envelope->occurredAt),
            'ingested_at' => $this->formatTimestamp($envelope->ingestedAt),
            'idempotency_key' => $envelope->idempotencyKey,
            'trace_id' => $envelope->traceId,
            'payload' => $envelope->payload,
            'key_id' => $keyId,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateFieldSet(array $document): void
    {
        $fields = array_keys($document);
        sort($fields, SORT_STRING);
        $required = self::REQUIRED_FIELDS;
        sort($required, SORT_STRING);

        if ($fields !== $required) {
            throw new UnexpectedValueException('Monitoring envelope fields are invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateUnsignedDocument(array $document): void
    {
        if (($document['schema_version'] ?? null) !== 1) {
            throw new UnexpectedValueException('Monitoring envelope version is unsupported.');
        }

        if (! $this->validUuid($document['message_id'] ?? null)
            || ! $this->validUuid($document['trace_id'] ?? null)
            || ! $this->validIdentifier($document['source'] ?? null, 128)
            || ! $this->validIdentifier($document['idempotency_key'] ?? null, 128)
            || ! $this->validIdentifier($document['key_id'] ?? null, 128)
            || ! is_int($document['sequence'] ?? null)
            || $document['sequence'] < 1
            || ! is_array($document['payload'] ?? null)
            || ! is_string($document['type'] ?? null)
            || RuntimeMessageType::tryFrom($document['type']) === null) {
            throw new UnexpectedValueException('Monitoring envelope fields are invalid.');
        }

        $this->timestamp($document['occurred_at'] ?? null);
        $this->timestamp($document['ingested_at'] ?? null);

        if (array_key_exists('signature', $document) && ! is_string($document['signature'])) {
            throw new UnexpectedValueException('Monitoring envelope signature is invalid.');
        }
    }

    private function validIdentifier(mixed $value, int $maximumLength): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximumLength;
    }

    private function validUuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    private function validateEncodedSize(string $encoded): void
    {
        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new UnexpectedValueException('Monitoring envelope exceeds the maximum encoded size.');
        }
    }

    /**
     * Validate before recursive canonicalisation so untrusted structures cannot
     * exhaust the stack or allocate an unbounded canonical copy.
     *
     * @param  array<string|int, mixed>  $payload
     */
    private function validatePayload(array $payload): void
    {
        $stack = [[$payload, 1]];
        $nodeCount = 0;
        $textBytes = 0;

        while ($stack !== []) {
            [$value, $depth] = array_pop($stack);
            $nodeCount++;

            if ($nodeCount > self::MAX_TOTAL_NODES) {
                throw new UnexpectedValueException('Monitoring envelope payload exceeds the maximum node count.');
            }

            if (is_array($value)) {
                if ($depth > self::MAX_PAYLOAD_DEPTH) {
                    throw new UnexpectedValueException('Monitoring envelope payload exceeds the maximum depth.');
                }

                if (count($value) > self::MAX_CONTAINER_ITEMS) {
                    throw new UnexpectedValueException('Monitoring envelope payload exceeds the maximum container breadth.');
                }

                foreach ($value as $key => $item) {
                    if (is_string($key)) {
                        $keyBytes = strlen($key);

                        if ($keyBytes > self::MAX_KEY_BYTES) {
                            throw new UnexpectedValueException('Monitoring envelope payload exceeds the maximum string or key size.');
                        }

                        $textBytes += $keyBytes;
                    }

                    $stack[] = [$item, $depth + 1];
                }

                continue;
            }

            if (is_string($value)) {
                $stringBytes = strlen($value);

                if ($stringBytes > self::MAX_STRING_BYTES) {
                    throw new UnexpectedValueException('Monitoring envelope payload exceeds the maximum string or key size.');
                }

                $textBytes += $stringBytes;
            } elseif (is_float($value) && ! is_finite($value)) {
                throw new UnexpectedValueException('Monitoring envelope payload contains an unsupported value.');
            } elseif (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_float($value)) {
                throw new UnexpectedValueException('Monitoring envelope payload contains an unsupported value.');
            }

            if ($textBytes > self::MAX_ENCODED_BYTES) {
                throw new UnexpectedValueException('Monitoring envelope exceeds the maximum encoded size.');
            }
        }
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Monitoring envelope timestamp is invalid.');
        }

        try {
            $timestamp = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, 'UTC');
        } catch (\Throwable $exception) {
            throw new UnexpectedValueException('Monitoring envelope timestamp is invalid.', previous: $exception);
        }

        if ($timestamp === false || $this->formatTimestamp($timestamp) !== $value) {
            throw new UnexpectedValueException('Monitoring envelope timestamp is invalid.');
        }

        return $timestamp;
    }

    private function formatTimestamp(CarbonImmutable $timestamp): string
    {
        return $timestamp->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function canonicalise(mixed $value): mixed
    {
        if (is_object($value)) {
            throw new UnexpectedValueException('Monitoring envelope payload contains an unsupported value.');
        }

        // PHP emits both -0.0 and 0.0 as integer-shaped JSON while leaving the
        // caller's values typed as floats. Normalise the canonical copy so a
        // freshly encoded envelope is byte-identical after JSON decoding.
        if (is_float($value) && $value == 0.0) {
            return 0;
        }

        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalise($item);
        }

        if (! array_is_list($canonical)) {
            uksort($canonical, static fn (string|int $left, string|int $right): int => strcmp((string) $left, (string) $right));
        }

        return $canonical;
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Monitoring envelope JSON is invalid.', previous: $exception);
        }
    }
}
