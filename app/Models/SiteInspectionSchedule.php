<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteInspectionSchedule extends Model
{
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $fillable = [
        'site_id',
        'inspection_type',
        'title',
        'description',
        'frequency',
        'custom_rrule',
        'first_due_date',
        'next_due_date',
        'assigned_to_user_id',
        'auto_create_calendar_event',
        'is_active',
    ];

    protected $casts = [
        'first_due_date' => 'date',
        'next_due_date' => 'date',
        'auto_create_calendar_event' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(SiteInspectionRecord::class, 'schedule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDue($query)
    {
        return $query->where('next_due_date', '<=', now()->toDateString());
    }

    public function isDue(): bool
    {
        return $this->next_due_date && $this->next_due_date <= now()->toDateString();
    }
}
