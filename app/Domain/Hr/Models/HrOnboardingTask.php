<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrOnboardingTask extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'checklist_id',
        'category',
        'title',
        'description',
        'is_required',
        'sort_order',
        'assigned_to_user_id',
        'assigned_to_role',
        'status',
        'due_date',
        'dependency_task_ids',
        'completed_at',
        'completed_by',
        'evidence_path',
        'hr_document_id',
        'sign_off_required',
        'signed_off_by',
        'signed_off_at',
        'notes',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
        'due_date' => 'date',
        'dependency_task_ids' => 'array',
        'completed_at' => 'datetime',
        'sign_off_required' => 'boolean',
        'signed_off_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingChecklist::class, 'checklist_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrDocument::class, 'hr_document_id');
    }
}
