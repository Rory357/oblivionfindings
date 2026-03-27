<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinDonorFundReport extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_donor_fund_reports';

    protected $fillable = [
        'fund_id',
        'report_name',
        'period_from',
        'period_to',
        'opening_balance',
        'total_receipts',
        'total_expenditure',
        'closing_balance',
        'report_data',
        'status',
        'submitted_at',
        'file_path',
        'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'submitted_at' => 'datetime',
        'opening_balance' => 'decimal:2',
        'total_receipts' => 'decimal:2',
        'total_expenditure' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'report_data' => 'array',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinDonorFund::class, 'fund_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
