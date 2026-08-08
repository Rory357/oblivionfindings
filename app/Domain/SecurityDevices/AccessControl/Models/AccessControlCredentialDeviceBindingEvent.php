<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class AccessControlCredentialDeviceBindingEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_UNCONFIRMED = 'unconfirmed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REMOVED = 'removed';

    protected $table = 'access_control_credential_device_binding_events';

    protected $fillable = [
        'access_credential_id',
        'site_id',
        'device_id',
        'sequence',
        'binding_status',
        'provider_action',
        'provider_reconciliation_status',
        'provider_request_key',
        'provider_event_key',
        'provider_confirmed',
        'occurred_at',
        'recorded_by_user_id',
        'binding_snapshot',
        'created_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'provider_confirmed' => 'boolean',
        'occurred_at' => 'immutable_datetime',
        'binding_snapshot' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AccessControlCredentialDeviceBindingEvent $event): void {
            $confirmedState = $event->provider_confirmed
                && $event->provider_reconciliation_status === AccessControlCredential::RECONCILIATION_RECONCILED
                && in_array($event->binding_status, [self::STATUS_ACTIVE, self::STATUS_REMOVED], true)
                && (($event->binding_status === self::STATUS_ACTIVE
                        && $event->provider_action === AccessControlCredential::PROVIDER_ACTION_ISSUE)
                    || ($event->binding_status === self::STATUS_REMOVED
                        && $event->provider_action === AccessControlCredential::PROVIDER_ACTION_REVOKE))
                && filled($event->provider_request_key)
                && filled($event->provider_event_key)
                && (int) data_get($event->binding_snapshot, 'site_id') === (int) $event->site_id
                && (int) data_get($event->binding_snapshot, 'device_id') === (int) $event->device_id;
            $legacyState = ! $event->provider_confirmed
                && $event->binding_status === self::STATUS_UNCONFIRMED
                && $event->provider_reconciliation_status === AccessControlCredential::RECONCILIATION_REQUIRED
                && blank($event->provider_request_key)
                && blank($event->provider_event_key);
            if (! $confirmedState && ! $legacyState) {
                throw new UnexpectedValueException('Credential-device binding evidence is not a truthful provider-confirmed or legacy-unconfirmed state.');
            }
        });
        static::updating(static function (): never {
            throw new UnexpectedValueException('Access-control credential-device binding events are immutable.');
        });
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control credential-device binding events are retained as immutable provider evidence.');
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

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
