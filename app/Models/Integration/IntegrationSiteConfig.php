<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationSiteConfig extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const STATUS_TENANT_ONLY = 'tenant_only';
    public const STATUS_HYBRID = 'hybrid';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $table = 'integration_site_configs';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'provider',
        'status',
        'mapped_external_site_id',
        'mapped_external_site_name',
        'overrides',
        'is_active',
    ];

    protected $casts = [
        'overrides' => 'array',
        'is_active' => 'boolean',
    ];

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function siteSecrets(): HasMany
    {
        return $this->hasMany(IntegrationSiteSecret::class, 'site_id', 'site_id')
            ->whereColumn('integration_site_secrets.provider', 'integration_site_configs.provider');
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
