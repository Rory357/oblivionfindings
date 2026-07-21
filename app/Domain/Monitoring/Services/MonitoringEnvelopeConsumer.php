<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Models\MonitoringConsumerCheckpoint;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

final class MonitoringEnvelopeConsumer
{
    public function __construct(
        private readonly RuntimeEnvelopeCodec $codec,
        private readonly RuntimeEnvelopeHandlerRegistry $handlers,
    ) {}

    public function consume(string $consumer, string $encoded, ?int $trustedSiteId = null): void
    {
        DB::transaction(function () use ($consumer, $encoded, $trustedSiteId): void {
            try {
                $envelope = $this->codec->decode($encoded);
            } catch (UnexpectedValueException $exception) {
                $this->parkInvalid($consumer, $encoded, $trustedSiteId, $exception);

                return;
            }

            $incomingHash = hash('sha256', $encoded);
            $inbox = MonitoringInbox::query()
                ->where('consumer', $consumer)
                ->where('message_id', $envelope->messageId)
                ->lockForUpdate()
                ->first();

            if ($inbox === null) {
                $idempotencyCollision = MonitoringInbox::query()
                    ->where('consumer', $consumer)
                    ->where('source', $envelope->source)
                    ->where('idempotency_key', $envelope->idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($idempotencyCollision !== null) {
                    $this->park($consumer, $envelope, $encoded, $trustedSiteId, 'payload_invalid', 'Idempotency key was reused.');

                    return;
                }

                $inbox = MonitoringInbox::create([
                    'consumer' => $consumer,
                    'message_id' => $envelope->messageId,
                    'source' => $envelope->source,
                    'sequence' => $envelope->sequence,
                    'idempotency_key' => $envelope->idempotencyKey,
                    'payload_hash' => $incomingHash,
                    'envelope_bytes' => $encoded,
                ]);
            }

            if (! hash_equals($inbox->payload_hash, $incomingHash)) {
                $this->park($consumer, $envelope, $encoded, $trustedSiteId, 'payload_invalid', 'Message id was reused with different bytes.');

                return;
            }

            if ($inbox->processed_at !== null) {
                return;
            }

            $checkpoint = MonitoringConsumerCheckpoint::query()
                ->where('consumer', $consumer)
                ->where('source', $envelope->source)
                ->lockForUpdate()
                ->first();

            if ($checkpoint === null) {
                $checkpoint = MonitoringConsumerCheckpoint::create([
                    'consumer' => $consumer,
                    'source' => $envelope->source,
                    'last_sequence' => 0,
                ]);
            }

            $expected = $checkpoint->last_sequence + 1;

            if ($envelope->sequence !== $expected) {
                $reason = $envelope->sequence > $expected ? 'sequence_gap' : 'payload_invalid';
                $message = $reason === 'sequence_gap' ? "Expected sequence {$expected}." : 'Sequence has already advanced.';

                if ($reason === 'sequence_gap') {
                    $checkpoint->forceFill([
                        'gap_from' => $expected,
                        'gap_to' => $envelope->sequence - 1,
                    ])->save();
                }

                $this->park($consumer, $envelope, $encoded, $trustedSiteId, $reason, $message);

                return;
            }

            try {
                $this->handlers->for($envelope->type)->handle($envelope, $trustedSiteId);
            } catch (RuntimePayloadInvalid) {
                $this->park(
                    $consumer,
                    $envelope,
                    $encoded,
                    $trustedSiteId,
                    'payload_invalid',
                    'Envelope payload is invalid.',
                );

                return;
            } catch (RuntimeSiteScopeViolation) {
                $this->park(
                    $consumer,
                    $envelope,
                    $encoded,
                    $trustedSiteId,
                    'site_scope_violation',
                    'Envelope site scope did not match its canonical target.',
                );

                return;
            } catch (RuntimeScopeViolation) {
                $this->park(
                    $consumer,
                    $envelope,
                    $encoded,
                    $trustedSiteId,
                    'scope_violation',
                    'Envelope target scope did not match its canonical record.',
                );

                return;
            }
            $inbox->forceFill(['processed_at' => now()])->save();
            $checkpoint->forceFill([
                'last_sequence' => $envelope->sequence,
                'gap_from' => null,
                'gap_to' => null,
            ])->save();
        }, 3);
    }

    public function parkHandlerFailure(
        string $consumer,
        string $encoded,
        ?int $trustedSiteId = null,
    ): void {
        DB::transaction(function () use ($consumer, $encoded, $trustedSiteId): void {
            try {
                $envelope = $this->codec->decode($encoded);
            } catch (UnexpectedValueException $exception) {
                $this->parkInvalid($consumer, $encoded, $trustedSiteId, $exception);

                return;
            }

            $incomingHash = hash('sha256', $encoded);
            $inbox = MonitoringInbox::query()
                ->where('consumer', $consumer)
                ->where('message_id', $envelope->messageId)
                ->lockForUpdate()
                ->first();

            if ($inbox !== null && (! hash_equals($inbox->payload_hash, $incomingHash)
                || ! hash_equals($inbox->envelope_bytes, $encoded))) {
                $this->park(
                    $consumer,
                    $envelope,
                    $encoded,
                    $trustedSiteId,
                    'payload_invalid',
                    'Message id was reused with different bytes.',
                );

                return;
            }

            if ($inbox?->processed_at !== null) {
                return;
            }

            $this->park($consumer, $envelope, $encoded, $trustedSiteId, 'handler_failed', 'Handler retry limit was exhausted.');
        });
    }

    private function parkInvalid(
        string $consumer,
        string $encoded,
        ?int $trustedSiteId,
        UnexpectedValueException $exception,
    ): void {
        $metadata = $this->untrustedMetadata($encoded);
        $message = $exception->getMessage();
        $reasonCode = match (true) {
            str_contains($message, 'version is unsupported') => 'unsupported_version',
            str_contains($message, 'signature'), str_contains($message, 'signing key') => 'invalid_signature',
            default => 'payload_invalid',
        };

        $this->createDeadLetter([
            'message_id' => $metadata['message_id'],
            'consumer' => $consumer,
            'source' => $metadata['source'],
            'sequence' => $metadata['sequence'],
            'idempotency_key' => $metadata['idempotency_key'],
            'reason_code' => $reasonCode,
            'reason_message' => match ($reasonCode) {
                'unsupported_version' => 'Envelope version is unsupported.',
                'invalid_signature' => 'Envelope authentication failed.',
                default => 'Envelope payload is invalid.',
            },
            'envelope_bytes' => $encoded,
            'site_id' => $trustedSiteId,
        ]);
    }

    private function park(
        string $consumer,
        RuntimeEnvelope $envelope,
        string $encoded,
        ?int $trustedSiteId,
        string $reasonCode,
        string $reasonMessage,
    ): void {
        $this->createDeadLetter([
            'message_id' => $envelope->messageId,
            'consumer' => $consumer,
            'source' => $envelope->source,
            'sequence' => $envelope->sequence,
            'idempotency_key' => $envelope->idempotencyKey,
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
            'envelope_bytes' => $encoded,
            'site_id' => $trustedSiteId,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createDeadLetter(array $attributes): void
    {
        $timestamp = now();
        $fingerprint = hash('sha256', $attributes['envelope_bytes']);
        $siteId = $attributes['site_id'] === null ? null : (int) $attributes['site_id'];
        $dedupeKey = MonitoringDeadLetter::dedupeKey(
            (string) $attributes['consumer'],
            (string) $attributes['message_id'],
            (string) $attributes['reason_code'],
            $siteId,
            $fingerprint,
        );

        $inserted = MonitoringDeadLetter::query()->insertOrIgnore([
            ...$attributes,
            'evidence_fingerprint' => $fingerprint,
            'dedupe_key' => $dedupeKey,
            'replay_count' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($inserted === 1) {
            return;
        }

        $existing = MonitoringDeadLetter::query()
            ->where('dedupe_key', $dedupeKey)
            ->lockForUpdate()
            ->first();

        if ($existing === null
            || $existing->consumer !== $attributes['consumer']
            || $existing->message_id !== $attributes['message_id']
            || $existing->reason_code !== $attributes['reason_code']
            || $existing->site_id !== $siteId
            || ! hash_equals($existing->evidence_fingerprint, $fingerprint)
            || ! hash_equals($existing->envelope_bytes, $attributes['envelope_bytes'])) {
            throw new UnexpectedValueException('Dead-letter evidence identity conflict.');
        }
    }

    /** @return array{message_id: string, source: string, sequence: int, idempotency_key: string} */
    private function untrustedMetadata(string $encoded): array
    {
        try {
            $document = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $document = [];
        }

        $messageId = is_array($document) && is_string($document['message_id'] ?? null)
            && Str::isUuid($document['message_id']) ? $document['message_id'] : $this->messageIdForBytes($encoded);
        $source = is_array($document) && is_string($document['source'] ?? null)
            && $document['source'] !== '' && strlen($document['source']) <= 128 ? $document['source'] : 'untrusted';
        $sequence = is_array($document) && is_int($document['sequence'] ?? null)
            && $document['sequence'] > 0 ? $document['sequence'] : 1;
        $idempotencyKey = is_array($document) && is_string($document['idempotency_key'] ?? null)
            && $document['idempotency_key'] !== '' && strlen($document['idempotency_key']) <= 128
            ? $document['idempotency_key'] : 'invalid:'.substr(hash('sha256', $encoded), 0, 32);

        return [
            'message_id' => $messageId,
            'source' => $source,
            'sequence' => $sequence,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function messageIdForBytes(string $encoded): string
    {
        $hex = substr(hash('sha256', $encoded), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x03) | 0x08);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
