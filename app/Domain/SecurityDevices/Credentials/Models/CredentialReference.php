<?php

namespace App\Domain\SecurityDevices\Credentials\Models;

use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use UnexpectedValueException;

class CredentialReference extends Model
{
    protected $table = 'security_device_credential_references';

    protected $fillable = [
        'reference_uuid', 'reference_key', 'site_id', 'provider', 'purpose',
        'capabilities', 'secret_manager_reference', 'secret_manager_reference_hash',
        'status', 'rotation_status', 'test_status', 'version', 'created_by_user_id',
        'last_rotated_by_user_id', 'last_tested_at', 'last_rotated_at', 'revoked_at',
    ];

    protected $hidden = [
        'secret_manager_reference',
        'secret_manager_reference_hash',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'secret_manager_reference' => 'encrypted',
        'status' => CredentialReferenceStatus::class,
        'rotation_status' => CredentialRotationStatus::class,
        'test_status' => CredentialTestStatus::class,
        'version' => 'integer',
        'last_tested_at' => 'immutable_datetime',
        'last_rotated_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $reference): void {
            $reference->reference_uuid ??= (string) Str::orderedUuid();
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Credential references are retained as audit evidence.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference_uuid';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastRotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_rotated_by_user_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(CredentialReferenceAuditEvent::class);
    }

    public function leaseAuditEvents(): HasMany
    {
        return $this->hasMany(CredentialLeaseAuditEvent::class);
    }

    public function leaseGrants(): HasMany
    {
        return $this->hasMany(CredentialLeaseGrant::class);
    }
}
