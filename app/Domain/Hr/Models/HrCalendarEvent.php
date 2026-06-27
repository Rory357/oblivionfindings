<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCalendarEvent extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'event_type',
        'starts_at',
        'ends_at',
        'is_all_day',
        'rrule',
        'recurrence_until',
        'recurrence_parent_id',
        'is_exception',
        'exception_date',
        'location',
        'department',
        'department_id',
        'category_id',
        'site_id',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_all_day' => 'boolean',
        'recurrence_until' => 'datetime',
        'is_exception' => 'boolean',
        'exception_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Named `departmentRef` (not `department`) to avoid shadowing the legacy
     * free-text `department` column, which still exists for back-compat.
     */
    public function departmentRef(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HrCalendarEventCategory::class, 'category_id');
    }

    public function attendees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HrCalendarEventAttendee::class, 'event_id');
    }

    public function reminders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HrCalendarEventReminder::class, 'event_id');
    }

    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HrCalendarEventAttachment::class, 'event_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeInRange(Builder $query, string $start, string $end): Builder
    {
        return $query->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start);
    }
}
