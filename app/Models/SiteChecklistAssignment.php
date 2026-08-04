<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteChecklistAssignment extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'site_id',
        'template_id',
        'frequency',
        'custom_rrule',
        'assigned_to_role_id',
        'assigned_to_user_id',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistTemplate::class, 'template_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SiteChecklistRun::class, 'assignment_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
