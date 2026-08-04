<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCoverageRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'service_context_id',
        'preferred_client_id',
        'name',
        'coverage_type',
        'day_of_week',
        'starts_time',
        'ends_time',
        'minimum_staff',
        'role_requirements',
        'allow_overstaffing',
        'shift_type',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'minimum_staff' => 'integer',
        'role_requirements' => 'array',
        'allow_overstaffing' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function preferredClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'preferred_client_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
