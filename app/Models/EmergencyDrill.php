<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyDrill extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'drill_type',
        'title',
        'description',
        'scheduled_at',
        'started_at',
        'completed_at',
        'duration_minutes',
        'evacuation_time_seconds',
        'status',
        'outcome',
        'scenario_description',
        'total_participants',
        'residents_evacuated',
        'all_areas_checked',
        'assembly_point_reached',
        'roll_call_completed',
        'weather_conditions',
        'observer_notes',
        'improvements_identified',
        'conducted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_minutes' => 'integer',
        'evacuation_time_seconds' => 'integer',
        'total_participants' => 'integer',
        'residents_evacuated' => 'integer',
        'all_areas_checked' => 'boolean',
        'assembly_point_reached' => 'boolean',
        'roll_call_completed' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EmergencyDrillParticipant::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(EmergencyDrillFinding::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('scheduled_at', '>=', now())
            ->where('status', 'scheduled');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('drill_type', $type);
    }
}
