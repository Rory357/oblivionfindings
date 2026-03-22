<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCompensationReviewItem extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'compensation_review_id',
        'employee_profile_id',
        'current_salary',
        'proposed_salary',
        'change_percentage',
        'justification',
        'status',
        'approved_by',
    ];

    protected $casts = [
        'current_salary' => 'encrypted',
        'proposed_salary' => 'encrypted',
        'change_percentage' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function review(): BelongsTo
    {
        return $this->belongsTo(HrCompensationReview::class, 'compensation_review_id');
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
