<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkforceAvailabilityCoverageAction extends Model
{
    use AuditableChanges, WritesLegacyOrganizationStorageContext;

    public const SOURCE_LEAVE = 'hr_leave_request';

    public const SOURCE_OFFBOARDING = 'hr_offboarding_checklist';

    public const KIND_REPLACEMENT = 'replacement';

    public const KIND_HANDOVER = 'handover';

    public const STATUS_OPEN = 'open';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'source_type',
        'source_id',
        'shift_id',
        'replacement_request_id',
        'owner_user_id',
        'action_kind',
        'status',
        'window_starts_at',
        'window_ends_at',
        'manages_replacement',
        'created_by',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'manages_replacement' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function replacementRequest(): BelongsTo
    {
        return $this->belongsTo(ShiftReplacementRequest::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
