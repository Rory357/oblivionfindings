<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use Illuminate\Database\Eloquent\Model;

final class CctvLiveSession extends Model
{
    protected $table = 'cctv_live_sessions';

    protected $guarded = [];

    protected $casts = ['generation' => 'integer', 'authority_until' => 'immutable_datetime', 'session_expires_at' => 'immutable_datetime', 'ice_expires_at' => 'immutable_datetime', 'material' => 'encrypted:array', 'worker_grant' => 'encrypted:array', 'source_context' => 'encrypted:array', 'preparation_started_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(function (self $session): void {
            if ($session->isDirty('provider')) {
                throw new \LogicException('Live session provider is immutable.');
            }
            if ($session->getRawOriginal('provider') === 'hikvision') {
                if (in_array($session->getRawOriginal('status'), ['stopped', 'expired', 'revoked', 'failed'], true) && ($session->isDirty('status') || $session->isDirty('generation') || $session->isDirty('authority_until'))) {
                    throw new \LogicException('Terminal live session authority is immutable.');
                }
                foreach (['uuid', 'source_id', 'camera_id', 'actor_id', 'collector_id', 'actor_fingerprint', 'browser_fingerprint', 'request_key', 'request_hash', 'purpose', 'source_context', 'preparation_started_at'] as $field) {
                    if ($session->isDirty($field)) {
                        throw new \LogicException('Live session authority is immutable.');
                    }
                }
                if ($session->getRawOriginal('credential_lease_grant_id') !== null && ($session->isDirty('credential_lease_grant_id') || $session->isDirty('session_expires_at') || $session->isDirty('ice_expires_at'))) {
                    throw new \LogicException('Prepared live session credential and lifetime are immutable.');
                }
            }
        });
        self::deleting(function (self $session): void {
            if ($session->provider === 'hikvision') {
                throw new \LogicException('Live session authority receipts are retained.');
            }
        });
    }

    protected $hidden = ['material', 'worker_grant', 'actor_fingerprint', 'browser_fingerprint', 'request_hash', 'source_context', 'credential_lease_grant_id', 'preparation_started_at'];
}
