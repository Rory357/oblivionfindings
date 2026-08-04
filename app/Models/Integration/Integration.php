<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Integration extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ERROR = 'error';

    protected $table = 'integrations';

    protected $fillable = [
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

    public function providerConnection(): HasOne
    {
        return $this->hasOne(IntegrationProviderConnection::class, 'provider', 'provider');
    }

    public function siteConfigs(): HasMany
    {
        return $this->hasMany(IntegrationSiteConfig::class, 'provider', 'provider');
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
