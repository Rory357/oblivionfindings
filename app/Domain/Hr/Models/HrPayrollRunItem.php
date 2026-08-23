<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayrollRunItem extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'user_id',
        'timesheet_ids',
        'base_hourly_rate',
        'overtime_multiplier',
        'public_holiday_multiplier',
        'sleepover_rate',
        'on_call_rate',
        'regular_hours',
        'overtime_hours',
        'sleepover_count',
        'on_call_hours',
        'mileage_km',
        'public_holiday_hours',
        'leave_hours',
        'leave_pay',
        'gross_pay',
        'allowances',
        'rate_breakdown',
        'notes',
    ];

    protected $casts = [
        'timesheet_ids' => 'array',
        'base_hourly_rate' => 'decimal:2',
        'overtime_multiplier' => 'decimal:2',
        'public_holiday_multiplier' => 'decimal:2',
        'sleepover_rate' => 'decimal:2',
        'on_call_rate' => 'decimal:2',
        'regular_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'sleepover_count' => 'integer',
        'on_call_hours' => 'decimal:2',
        'mileage_km' => 'decimal:2',
        'public_holiday_hours' => 'decimal:2',
        'leave_hours' => 'decimal:2',
        'leave_pay' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'allowances' => 'array',
        'rate_breakdown' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceUses(): HasMany
    {
        return $this->hasMany(HrPayrollSourceUse::class, 'payroll_run_item_id');
    }
}
