<?php

namespace App\Domain\SecurityDevices\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class CredentialLeaseAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'security_device_credential_lease_audits';

    protected $fillable = [
        'credential_reference_id', 'site_id', 'action', 'reference_fingerprint',
        'lease_fingerprint', 'capabilities', 'safe_context', 'expires_at', 'occurred_at',
    ];

    protected $hidden = ['reference_fingerprint', 'lease_fingerprint'];

    protected $casts = [
        'capabilities' => 'array',
        'safe_context' => 'array',
        'expires_at' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new UnexpectedValueException('Credential lease audit evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Credential lease audit evidence is immutable.');
        });
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(CredentialReference::class, 'credential_reference_id');
    }
}
