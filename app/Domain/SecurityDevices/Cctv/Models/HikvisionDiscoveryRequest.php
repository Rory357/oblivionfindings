<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class HikvisionDiscoveryRequest extends Model
{
    protected $table = 'cctv_hikvision_discovery_requests';

    protected $guarded = [];

    protected $hidden = ['input_hash', 'actor_fingerprint', 'context_fingerprint', 'credential_fingerprint', 'configuration', 'operation', 'operation_hash', 'result', 'result_hash'];

    protected $casts = ['source_revision' => 'integer', 'configuration' => 'encrypted:array', 'operation' => 'encrypted:array', 'result' => 'encrypted:array', 'preparation_until' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'authority_until' => 'immutable_datetime', 'request_expires_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    protected static function booted(): void
    {
        self::updating(function (self $row): void {
            foreach (['uuid', 'actor_id', 'device_id', 'site_id', 'collector_id', 'discovery_scope_id', 'credential_reference_id', 'request_key', 'input_hash', 'source_revision', 'actor_fingerprint', 'context_fingerprint', 'credential_fingerprint', 'purpose', 'configuration', 'request_expires_at'] as $field) {
                if ($row->isDirty($field)) {
                    throw new LogicException('Discovery request identity is immutable.');
                }
            }
            $previous = $row->getRawOriginal('status');
            $transitions = [
                'queued' => ['preparing', 'revoked', 'expired'],
                'preparing' => ['dispatched', 'failed', 'revoked', 'expired'],
                'dispatched' => ['observed', 'failed', 'revoked', 'expired'],
            ];
            if ($row->isDirty('status') && ! in_array($row->status, $transitions[$previous] ?? [], true)) {
                throw new LogicException('Discovery request state cannot be reopened or skipped.');
            }
            if ($row->getRawOriginal('operation_hash') !== null) {
                foreach (['operation_hash', 'credential_lease_grant_id', 'issued_at', 'authority_until'] as $field) {
                    if ($row->isDirty($field)) {
                        throw new LogicException('Signed discovery binding is immutable.');
                    }
                }
                // Retain its signed identity; only terminal cleanup may erase
                // the sealed operation, and a used request can never replace it.
                if ($row->isDirty('operation') && (($row->getAttributes()['operation'] ?? null) !== null
                    || ! in_array($row->status, ['observed', 'failed', 'revoked', 'expired'], true))) {
                    throw new LogicException('Signed discovery operation cannot be replaced.');
                }
            }
            if ($row->getRawOriginal('result_hash') !== null) {
                foreach (['result', 'result_hash', 'observed_at', 'status', 'reason_code'] as $field) {
                    if ($row->isDirty($field)) {
                        throw new LogicException('Discovery result is immutable.');
                    }
                }
            }
        });
        self::deleting(function (): never {
            throw new LogicException('Discovery requests are retained as audit evidence.');
        });
    }
}
