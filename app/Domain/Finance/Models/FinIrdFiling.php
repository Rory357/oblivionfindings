<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinIrdFiling extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_ird_filings';

    protected $fillable = [
        'organization_id',
        'ird_number',
        'filing_type',
        'period_from',
        'period_to',
        'gst_return_id',
        'payroll_run_id',
        'filing_data',
        'total_amount',
        'status',
        'submitted_at',
        'ird_reference',
        'ird_response',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'filing_data' => 'encrypted:array',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'ird_response' => 'array',
    ];

    public function gstReturn(): BelongsTo
    {
        return $this->belongsTo(FinGstReturn::class, 'gst_return_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('filing_type', $type);
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
