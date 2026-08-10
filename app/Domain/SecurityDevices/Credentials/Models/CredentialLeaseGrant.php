<?php

namespace App\Domain\SecurityDevices\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use UnexpectedValueException;

class CredentialLeaseGrant extends Model
{
    public const string STATUS_ISSUED = 'issued';

    public const string STATUS_REVOKE_PENDING = 'revoke_pending';

    public const string STATUS_RELEASED = 'released';

    public const string STATUS_CONTAINED = 'contained';

    public const string STATUS_EXPIRED = 'expired';

    public $timestamps = false;

    protected $table = 'security_device_credential_lease_grants';

    protected $fillable = [
        'grant_uuid', 'credential_reference_id', 'reference_version', 'site_id',
        'lease_id', 'lease_fingerprint', 'capabilities', 'status', 'revoke_attempts',
        'last_failure_code', 'issued_at', 'expires_at', 'last_revoke_attempt_at', 'ended_at',
    ];

    protected $hidden = ['lease_id', 'lease_fingerprint'];

    protected $casts = [
        'reference_version' => 'integer',
        'site_id' => 'integer',
        'lease_id' => 'encrypted',
        'capabilities' => 'array',
        'revoke_attempts' => 'integer',
        'issued_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'last_revoke_attempt_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            $grant->grant_uuid ??= (string) Str::orderedUuid();
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Credential lease grants are retained as lifecycle evidence.');
        });
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(CredentialReference::class, 'credential_reference_id');
    }
}
