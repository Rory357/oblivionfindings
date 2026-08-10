<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrWellbeingIndicator extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'period_start',
        'period_end',
        'overtime_hours',
        'consecutive_days_worked',
        'sick_leave_days_30d',
        'sick_leave_days_90d',
        'shifts_worked_7d',
        'average_shift_length_hours',
        'flag_level',
        'calculated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'overtime_hours' => 'decimal:2',
        'consecutive_days_worked' => 'integer',
        'sick_leave_days_30d' => 'integer',
        'sick_leave_days_90d' => 'integer',
        'shifts_worked_7d' => 'integer',
        'average_shift_length_hours' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('flag_level', '!=', 'none');
    }
}
