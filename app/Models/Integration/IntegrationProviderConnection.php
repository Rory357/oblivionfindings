<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    protected $table = 'integration_tenant_secrets';

    protected $fillable = [
        'tenant_id',
        'provider',
        'secret_encrypted',
        'secret_last4',
        'status',
        'last_tested_at',
        'last_synced_at',
        'last_error',
        'config',
        'rotated_at',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'last_tested_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_encrypted',
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

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }
}
