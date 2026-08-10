<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use App\Traits\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveRequest extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'leave_type',
        'starts_at',
        'ends_at',
        'hours_requested',
        'reason',
        'supporting_doc_path',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'escalated_to',
        'time_off_id',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'hours_requested' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    
    public function timeOff(): BelongsTo
    {
        return $this->belongsTo(\App\Models\StaffTimeOff::class, 'time_off_id');
    }
}