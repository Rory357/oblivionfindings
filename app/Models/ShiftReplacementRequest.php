<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShiftReplacementRequest extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'shift_id',
        'requested_by',
        'current_staff_id',
        'replacement_user_id',
        'status',
        'reason',
        'notes',
        'required_skills',
        'requested_at',
        'claimed_at',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'required_skills' => 'array',
        'requested_at' => 'datetime',
        'claimed_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function currentStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_staff_id');
    }

    public function replacementStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replacement_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function openPosition(): HasOne
    {
        return $this->hasOne(ShiftOpenPosition::class, 'replacement_request_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['requested', 'claimed']);
    }
}
