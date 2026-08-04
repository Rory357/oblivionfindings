<?php

namespace App\Domain\SecurityDevices\Credentials\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class CredentialReferenceAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'security_device_credential_reference_audits';

    protected $fillable = [
        'credential_reference_id', 'actor_user_id', 'action', 'version', 'safe_context', 'occurred_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'safe_context' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new UnexpectedValueException('Credential reference audit evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Credential reference audit evidence is immutable.');
        });
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(CredentialReference::class, 'credential_reference_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
