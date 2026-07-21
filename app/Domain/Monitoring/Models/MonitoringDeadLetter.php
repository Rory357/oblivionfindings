<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Database\MonitoringDeadLetterBuilder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class MonitoringDeadLetter extends Model
{
    public const array IMMUTABLE_EVIDENCE_ATTRIBUTES = [
        'consumer',
        'message_id',
        'source',
        'sequence',
        'idempotency_key',
        'reason_code',
        'reason_message',
        'site_id',
        'envelope_bytes',
        'evidence_fingerprint',
        'dedupe_key',
    ];

    public static function dedupeKey(
        string $consumer,
        string $messageId,
        string $reasonCode,
        ?int $siteId,
        string $evidenceFingerprint,
    ): string {
        return hash('sha256', json_encode([
            $consumer,
            $messageId,
            $reasonCode,
            $siteId === null ? 'site:null' : "site:{$siteId}",
            $evidenceFingerprint,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $attributes */
    public static function withDerivedEvidenceIdentity(array $attributes): array
    {
        $envelopeBytes = $attributes['envelope_bytes'] ?? null;

        if (! is_string($envelopeBytes)) {
            throw new UnexpectedValueException('Monitoring dead-letter evidence bytes are required.');
        }

        $fingerprint = hash('sha256', $envelopeBytes);
        $siteId = ($attributes['site_id'] ?? null) === null ? null : (int) $attributes['site_id'];
        $dedupeKey = self::dedupeKey(
            (string) ($attributes['consumer'] ?? ''),
            (string) ($attributes['message_id'] ?? ''),
            (string) ($attributes['reason_code'] ?? ''),
            $siteId,
            $fingerprint,
        );

        foreach (['evidence_fingerprint' => $fingerprint, 'dedupe_key' => $dedupeKey] as $key => $expected) {
            if (array_key_exists($key, $attributes)
                && (! is_string($attributes[$key]) || ! hash_equals($expected, $attributes[$key]))) {
                throw new UnexpectedValueException('Monitoring dead-letter evidence identity is invalid.');
            }

            $attributes[$key] = $expected;
        }

        return $attributes;
    }

    /**
     * site_id is trusted intake/routing context. Callers must never derive it
     * from envelope_bytes, which remains untrusted evidence for review.
     */
    protected $fillable = [
        'message_id',
        'consumer',
        'source',
        'sequence',
        'idempotency_key',
        'reason_code',
        'reason_message',
        'envelope_bytes',
        'evidence_fingerprint',
        'dedupe_key',
        'site_id',
        'replay_count',
        'replay_requested_at',
        'replay_requested_by_user_id',
        'replay_request_reason',
        'replay_intent_token',
        'replay_dispatch_lease_until',
        'last_replayed_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_reason',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'site_id' => 'integer',
        'replay_count' => 'integer',
        'replay_requested_at' => 'immutable_datetime',
        'last_replayed_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'replay_dispatch_lease_until' => 'immutable_datetime',
    ];

    public function newEloquentBuilder($query): MonitoringDeadLetterBuilder
    {
        return new MonitoringDeadLetterBuilder($query);
    }

    protected function performInsert(Builder $query)
    {
        $this->forceFill(self::withDerivedEvidenceIdentity($this->getAttributes()));

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty(self::IMMUTABLE_EVIDENCE_ATTRIBUTES)) {
            throw new UnexpectedValueException('Monitoring dead-letter evidence identity is immutable.');
        }

        return parent::performUpdate($query);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function replayRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replay_requested_by_user_id');
    }
}
