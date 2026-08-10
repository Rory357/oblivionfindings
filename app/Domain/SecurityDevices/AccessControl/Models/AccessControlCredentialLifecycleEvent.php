<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class AccessControlCredentialLifecycleEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'access_control_credential_lifecycle_events';

    protected $fillable = [
        'access_credential_id',
        'site_id',
        'sequence',
        'event_type',
        'evidence_kind',
        'provider_action',
        'provider_request_key',
        'provider_event_key',
        'provider_confirmed',
        'occurred_at',
        'recorded_by_user_id',
        'legacy_revoked_at',
        'legacy_revoked_by_user_id',
        'legacy_revocation_reason',
        'credential_snapshot',
        'created_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'provider_confirmed' => 'boolean',
        'occurred_at' => 'immutable_datetime',
        'legacy_revoked_at' => 'immutable_datetime',
        'credential_snapshot' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AccessControlCredentialLifecycleEvent $event): void {
            if ($event->provider_confirmed
                && (blank($event->provider_request_key)
                    || blank($event->provider_event_key)
                    || $event->evidence_kind !== 'provider_confirmed'
                    || (int) data_get($event->credential_snapshot, 'site_id') !== (int) $event->site_id
                    || data_get($event->credential_snapshot, 'provider_reconciliation_action') !== $event->provider_action
                    || data_get($event->credential_snapshot, 'provider_reconciliation_request_key') !== $event->provider_request_key
                    || data_get($event->credential_snapshot, 'provider_reconciliation_event_key') !== $event->provider_event_key)) {
                throw new UnexpectedValueException('Provider-confirmed credential lifecycle evidence requires correlated request and event references.');
            }
            if (filled($event->provider_event_key) && blank($event->provider_request_key)) {
                throw new UnexpectedValueException('Credential lifecycle provider events require a correlated request reference.');
            }
            if (! $event->provider_confirmed
                && filled($event->provider_event_key)
                && $event->evidence_kind !== 'provider_reported') {
                throw new UnexpectedValueException('Unconfirmed provider lifecycle events must be recorded as provider-reported evidence.');
            }
        });
        static::updating(static function (): never {
            throw new UnexpectedValueException('Access-control credential lifecycle events are immutable.');
        });
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control credential lifecycle events are retained as immutable evidence.');
        });
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(AccessControlCredential::class, 'access_credential_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
