<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSupervisionNote extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'supervisor_user_id',
        'session_date',
        'session_type',
        'status',
        'cadence',
        'duration_minutes',
        'topics_discussed',
        'actions_agreed',
        'employee_comments',
        'employee_acknowledged',
        'employee_acknowledged_at',
        'next_session_date',
        'is_visible_to_employee',
        'created_by',
    ];

    protected $casts = [
        'session_date' => 'date',
        'duration_minutes' => 'integer',
        'actions_agreed' => 'array',
        'employee_acknowledged' => 'boolean',
        'employee_acknowledged_at' => 'datetime',
        'next_session_date' => 'date',
        'is_visible_to_employee' => 'boolean',
    ];

    public static function sessionTypeOptions(): array
    {
        return [
            ['value' => 'one_to_one', 'label' => 'One-to-One'],
            ['value' => 'supervision', 'label' => 'Supervision'],
            ['value' => 'review', 'label' => 'Review'],
            ['value' => 'check_in', 'label' => 'Check-in'],
            ['value' => 'probation', 'label' => 'Probation Review'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForEmployee(Builder $query, int $userId): Builder
    {
        return $query->where('employee_user_id', $userId);
    }
}
