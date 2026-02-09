<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteCalendarEvent extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'tenant_id',
        'event_type',
        'title',
        'description',
        'start_at',
        'end_at',
        'timezone',
        'recurrence_rule',
        'recurrence_parent_id',
        'recurrence_exceptions',
        'asset_id',
        'fleet_vehicle_id',
        'checklist_run_id',
        'hazard_id',
        'created_by_user_id',
        'owner_user_id',
        'attendee_user_ids',
        'status',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'approval_notes',
        'reminder_minutes',
        'last_reminder_sent_at',
        'attachments',
        'outcome_notes',
        'completed_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'approved_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'attendee_user_ids' => 'array',
        'reminder_minutes' => 'array',
        'recurrence_exceptions' => 'array',
        'attachments' => 'array',
    ];

    // Relationships
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_parent_id');
    }

    public function childEvents(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_parent_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(SiteCalendarEventException::class, 'parent_event_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fleetVehicle(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'fleet_vehicle_id');
    }

    // Scopes
    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeInRange($query, $start, $end)
    {
        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('start_at', [$start, $end])
              ->orWhere(function ($sq) use ($end) {
                  $sq->whereNotNull('recurrence_rule')
                     ->where('start_at', '<=', $end);
              });
        });
    }

    public function scopeRecurring($query)
    {
        return $query->whereNotNull('recurrence_rule');
    }

    public function scopePendingApproval($query)
    {
        return $query->where('approval_status', 'pending');
    }

    // Helpers
    public function isRecurring(): bool
    {
        return !empty($this->recurrence_rule);
    }

    public function requiresApproval(): bool
    {
        return $this->approval_status !== 'not_required';
    }
}
