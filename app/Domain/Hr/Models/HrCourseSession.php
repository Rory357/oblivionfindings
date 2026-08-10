<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCourseSession extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'course_id',
        'tenant_id',
        'session_date',
        'start_time',
        'end_time',
        'location',
        'online_link',
        'facilitator',
        'trainer_id',
        'max_participants',
        'waitlist_enabled',
        'status',
        'notes',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'session_date' => 'date',
        'max_participants' => 'integer',
        'waitlist_enabled' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function course(): BelongsTo
    {
        return $this->belongsTo(HrCourse::class, 'course_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(HrCourseEnrollment::class, 'session_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('session_date', '>=', now()->toDateString())
            ->where('status', 'scheduled')
            ->whereNull('cancelled_at');
    }
}
