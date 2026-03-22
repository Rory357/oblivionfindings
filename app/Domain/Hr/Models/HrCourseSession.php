<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCourseSession extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'course_id',
        'tenant_id',
        'session_date',
        'start_time',
        'end_time',
        'location',
        'facilitator',
        'max_participants',
        'status',
    ];

    protected $casts = [
        'session_date' => 'date',
        'max_participants' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function course(): BelongsTo
    {
        return $this->belongsTo(HrCourse::class, 'course_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(HrCourseEnrollment::class, 'session_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('session_date', '>=', now()->toDateString())
            ->where('status', 'scheduled');
    }
}
