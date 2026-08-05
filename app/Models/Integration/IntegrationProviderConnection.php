<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one application-level credential connection for a provider.
 *
 * The legacy table and partition column remain until the separately approved
 * storage migration. Neither is an authorization boundary.
 */
class IntegrationProviderConnection extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERROR = 'error';

    protected $table = 'integration_tenant_secrets';

    protected $fillable = [
        'provider',
        'secret_encrypted',
        'secret_last4',
        'status',
        'last_tested_at',
        'last_synced_at',
        'last_error',
        'config',
        'rotated_at',
        'disabled_at',
        'disabled_by',
        'disabled_reason',
        'requires_credential_replacement',
        'recovery_credentials_replaced_at',
        'recovery_credentials_replaced_by',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'last_tested_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'rotated_at' => 'datetime',
        'disabled_at' => 'datetime',
        'requires_credential_replacement' => 'boolean',
        'recovery_credentials_replaced_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_encrypted',
        'secretReferences',
        'config',
        'last_error',
    ];

    protected array $auditExcludedAttributes = [
        'secret_encrypted',
        'config',
        'last_error',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function secretReferences(): HasMany
    {
        return $this->hasMany(IntegrationSecretReference::class, 'provider_connection_id');
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_CONNECTED)
            ->where('requires_credential_replacement', false);
    }
}
