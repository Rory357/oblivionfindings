<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinConsolidationRun extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_consolidation_runs';

    protected $fillable = [
        'group_id',
        'period_from',
        'period_to',
        'fiscal_period_id',
        'status',
        'total_revenue',
        'total_expenses',
        'total_assets',
        'total_liabilities',
        'total_equity',
        'eliminations_count',
        'eliminations_amount',
        'report_data',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'total_revenue' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'total_assets' => 'decimal:2',
        'total_liabilities' => 'decimal:2',
        'total_equity' => 'decimal:2',
        'eliminations_amount' => 'decimal:2',
        'report_data' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationGroup::class, 'group_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FinFiscalPeriod::class, 'fiscal_period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
