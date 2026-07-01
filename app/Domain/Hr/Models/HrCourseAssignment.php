<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requirement placed on a person to complete a course by a due date. Rows are
 * expanded server-side from an audience selection (individuals / role / site /
 * cohort) and back both the Assignments tab and the Assign wizard.
 */
class HrCourseAssignment extends Model
{
    use AuditableChanges, HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrCourseAssignmentFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'hr_course_id',
        'session_id',
        'enrollment_id',
        'source',
        'source_ref',
        'assigned_by',
        'assigned_at',
        'due_at',
        'status',
        'score',
        'waived_reason',
        'reminded_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'due_at' => 'date',
        'reminded_at' => 'datetime',
        'score' => 'decimal:2',
    ];

    public const SOURCES = ['manual', 'role_rule', 'hs_requirement'];

    public const STATUSES = ['assigned', 'in_progress', 'completed', 'overdue', 'waived'];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(HrCourse::class, 'hr_course_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(HrCourseSession::class, 'session_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(HrCourseEnrollment::class, 'enrollment_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                            */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * "Effective" overdue: explicitly flagged, or past-due and not yet
     * completed/waived. Used by both the index badge and the dashboard.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', 'overdue')
                ->orWhere(function ($q2) {
                    $q2->whereNotIn('status', ['completed', 'waived'])
                        ->whereNotNull('due_at')
                        ->whereDate('due_at', '<', now()->toDateString());
                });
        });
    }

    /**
     * Returns the status the row should display, promoting past-due
     * non-terminal rows to "overdue" without a write.
     */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, ['completed', 'waived'], true)) {
            return $this->status;
        }

        if ($this->due_at && $this->due_at->isPast()) {
            return 'overdue';
        }

        return $this->status;
    }
}
