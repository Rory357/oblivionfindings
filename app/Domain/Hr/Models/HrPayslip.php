<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayslip extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'payroll_run_id',
        'employee_profile_id',
        'user_id',
        'pay_period_start',
        'pay_period_end',
        'payment_date',
        'gross_pay',
        'regular_hours',
        'overtime_hours',
        'hourly_rate',
        'paye',
        'acc_levy',
        'kiwisaver_employee',
        'kiwisaver_employer',
        'student_loan',
        'holiday_pay',
        'total_deductions',
        'net_pay',
        'allowances',
        'other_deductions',
        'tax_code',
        'kiwisaver_rate',
        'status',
        'pdf_path',
        'created_by',
    ];

    protected $casts = [
        'pay_period_start' => 'date',
        'pay_period_end' => 'date',
        'payment_date' => 'date',
        'gross_pay' => 'decimal:2',
        'regular_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'hourly_rate' => 'encrypted',
        'paye' => 'decimal:2',
        'acc_levy' => 'decimal:2',
        'kiwisaver_employee' => 'decimal:2',
        'kiwisaver_employer' => 'decimal:2',
        'student_loan' => 'decimal:2',
        'holiday_pay' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'allowances' => 'array',
        'other_deductions' => 'array',
        'kiwisaver_rate' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForPeriod(Builder $query, string $start, string $end): Builder
    {
        return $query->where('pay_period_start', '>=', $start)
            ->where('pay_period_end', '<=', $end);
    }
}
