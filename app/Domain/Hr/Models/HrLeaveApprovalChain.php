<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveApprovalChain extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'approver_user_id',
        'delegate_user_id',
        'approval_level',
        'escalation_after_hours',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'approval_level' => 'integer',
        'escalation_after_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }
}
