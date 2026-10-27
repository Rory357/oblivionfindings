<?php

namespace App\Domain\SecurityDevices\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Encrypted secret version row for the built-in database credential store.
 *
 * Never uses AuditableChanges so ciphertext and references are never copied
 * into audit_logs, and hides encrypted columns from array/JSON serialization.
 */
class MonitoringEncryptedSecret extends Model
{
    protected $table = 'monitoring_encrypted_secrets';

    protected $fillable = [
        'reference_hash',
        'opaque_reference_encrypted',
        'version',
        'encrypted_payload',
        'soft_deleted_at',
        'destroyed_at',
    ];

    protected $hidden = [
        'reference_hash',
        'opaque_reference_encrypted',
        'encrypted_payload',
    ];

    protected $casts = [
        'version' => 'integer',
        'soft_deleted_at' => 'datetime',
        'destroyed_at' => 'datetime',
    ];

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->getKey(),
            'version' => $this->version,
            'soft_deleted' => $this->soft_deleted_at !== null,
            'destroyed' => $this->destroyed_at !== null,
        ];
    }

    /** @return never */
    public function toArray(): array
    {
        throw new RuntimeException('Encrypted secret records cannot be converted to arrays.');
    }
}
