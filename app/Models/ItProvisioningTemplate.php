<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItProvisioningTemplate extends Model
{
    use HasFactory;

    public const LIFECYCLE_TYPES = ['joiner', 'mover', 'leaver'];

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'lifecycle_type',
        'position_role',
        'site_id',
        'employment_type',
        'selection_priority',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'selection_priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(ItProvisioningTemplateTask::class, 'provisioning_template_id')
            ->orderBy('stage')->orderBy('sort_order')->orderBy('id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
