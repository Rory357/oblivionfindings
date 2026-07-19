<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSiteSecret extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $table = 'integration_site_secrets';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'provider',
        'capability',
        'base_url',
        'secret_encrypted',
        'is_enabled',
        'last_tested_at',
        'last_error',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_encrypted',
    ];

    protected array $auditExcludedAttributes = [
        'secret_encrypted',
        'base_url',
        'last_error',
    ];

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForCapability(Builder $query, string $capability): Builder
    {
        return $query->where('capability', $capability);
    }
}
