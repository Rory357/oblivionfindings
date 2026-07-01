<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffTrainingRecord extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'user_id',
        'training_course_id',
        'hr_course_id',
        'status',
        'enrolled_at',
        'enrolled_by_user_id',
        'completed_at',
        'completion_date',
        'expires_at',
        'assessment_score',
        'assessment_passed',
        'assessment_notes',
        'certificate_number',
        'certificate_path',
        'provider',
        'trainer_name',
        'venue',
        'exemption_reason',
        'exempted_by_user_id',
        'exempted_at',
        'renewal_reminder_sent_at',
        'renewed_by_record_id',
        'cpd_points',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'completed_at' => 'datetime',
        'completion_date' => 'date',
        'expires_at' => 'datetime',
        'exempted_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
        'assessment_passed' => 'boolean',
    ];

    /**
     * Staff member.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Legacy training course (kept for backward-compatible reads).
     */
    public function trainingCourse(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class);
    }

    /**
     * Canonical catalog course (source of truth after unification).
     */
    public function hrCourse(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Hr\Models\HrCourse::class, 'hr_course_id');
    }

    /**
     * User who enrolled the staff member.
     */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }

    /**
     * User who exempted the staff member.
     */
    public function exemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exempted_by_user_id');
    }

    /**
     * Renewal record.
     */
    public function renewedByRecord(): BelongsTo
    {
        return $this->belongsTo(StaffTrainingRecord::class, 'renewed_by_record_id');
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Completed training.
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['completed', 'passed']);
    }

    /**
     * Scope: Valid (not expired) training.
     */
    public function scopeValid($query)
    {
        return $query->whereIn('status', ['completed', 'passed'])
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: Expired training.
     */
    public function scopeExpired($query)
    {
        return $query->whereIn('status', ['completed', 'passed', 'expired'])
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope: Expiring soon.
     */
    public function scopeExpiringSoon($query, int $months = 1)
    {
        return $query->whereIn('status', ['completed', 'passed'])
            ->whereBetween('expires_at', [now(), now()->addMonths($months)]);
    }

    /**
     * Check if training is valid.
     */
    public function isValid(): bool
    {
        return in_array($this->status, ['completed', 'passed'])
            && (!$this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * Check if training is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if expiring soon.
     */
    public function isExpiringSoon(int $months = 1): bool
    {
        return $this->expires_at
            && $this->expires_at->isFuture()
            && $this->expires_at->diffInMonths(now()) <= $months;
    }
}
