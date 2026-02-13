<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Integration extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ERROR = 'error';

    protected $table = 'integrations';

    protected $fillable = [
        'tenant_id',
        'provider',
        'display_name',
        'status',
        'last_tested_at',
        'last_error',
        'capabilities',
        'config',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'config' => 'array',
        'last_tested_at' => 'datetime',
    ];

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function tenantSecret(): HasOne
    {
        return $this->hasOne(IntegrationTenantSecret::class, 'provider', 'provider')
            ->whereColumn('integration_tenant_secrets.tenant_id', 'integrations.tenant_id');
    }

    public function siteConfigs(): HasMany
    {
        return $this->hasMany(IntegrationSiteConfig::class, 'provider', 'provider')
            ->whereColumn('integration_site_configs.tenant_id', 'integrations.tenant_id');
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
