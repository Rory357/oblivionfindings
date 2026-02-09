<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteChecklistTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'description',
        'applicable_to_type',
        'frequency',
        'custom_rrule',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SiteChecklistTemplateItem::class, 'template_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SiteChecklistAssignment::class, 'template_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SiteChecklistRun::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where(function ($q) use ($type) {
            $q->where('applicable_to_type', $type)
              ->orWhere('applicable_to_type', 'all');
        });
    }
}
