<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InitiativeSiteScope extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $table = 'roadmap_initiative_site_scopes';

    protected $fillable = [
        'tenant_id',
        'initiative_id',
        'scope_type',
        'rollout_mode',
        'wave_count',
        'constraints',
    ];

    protected $casts = [
        'constraints' => 'array',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(InitiativeSiteScopeSite::class, 'initiative_site_scope_id');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }
}
