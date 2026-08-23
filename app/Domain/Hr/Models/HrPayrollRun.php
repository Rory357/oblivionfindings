<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrPayrollRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrPayrollRun extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrPayrollRunFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'command_key_sha256',
        'command_payload_sha256',
        'source_provenance_status',
        'correction_of_run_id',
        'period_start',
        'period_end',
        'status',
        'locked_at',
        'locked_by',
        'exported_at',
        'exported_by',
        'export_profile_id',
        'export_format',
        'export_path',
        'total_hours',
        'total_gross',
        'total_staff',
        'notes',
        'validation_errors',
        'journal_id',
        'gl_posted_at',
        'gl_error',
        'net_paid_at',
        'voided_at',
        'voided_by',
        'void_reason',
        'payment_journal_id',
        'cost_allocated_at',
        'oncost_allocated_at',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'locked_at' => 'datetime',
        'exported_at' => 'datetime',
        'total_hours' => 'decimal:2',
        'total_gross' => 'decimal:2',
        'total_staff' => 'integer',
        'validation_errors' => 'array',
        'export_profile_id' => 'integer',
        'journal_id' => 'integer',
        'gl_posted_at' => 'datetime',
        'net_paid_at' => 'datetime',
        'voided_at' => 'datetime',
        'voided_by' => 'integer',
        'correction_of_run_id' => 'integer',
        'payment_journal_id' => 'integer',
        'cost_allocated_at' => 'datetime',
        'oncost_allocated_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function items(): HasMany
    {
        return $this->hasMany(HrPayrollRunItem::class, 'payroll_run_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(HrPayslip::class, 'payroll_run_id');
    }

    public function sourceUses(): HasMany
    {
        return $this->hasMany(HrPayrollSourceUse::class, 'payroll_run_id');
    }

    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_run_id');
    }

    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'correction_of_run_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function exportProfile(): BelongsTo
    {
        return $this->belongsTo(HrPayrollExportProfile::class, 'export_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->whereNotNull('locked_at');
    }
}
