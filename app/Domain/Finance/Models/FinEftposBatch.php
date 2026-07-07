<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinEftposBatch extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'fin_eftpos_batches';

    protected $fillable = [
        'organization_id',
        'terminal_id',
        'batch_number',
        'batch_date',
        'settlement_date',
        'total_transactions',
        'total_amount',
        'total_refunds',
        'net_amount',
        'fees',
        'settlement_amount',
        'status',
        'bank_transaction_id',
        'reconciled_at',
        'reconciled_by',
        'discrepancy_amount',
        'discrepancy_notes',
        'journal_id',
        'gl_posted_at',
        'created_by',
    ];

    protected $casts = [
        'batch_date' => 'date',
        'settlement_date' => 'date',
        'reconciled_at' => 'datetime',
        'gl_posted_at' => 'datetime',
        'total_transactions' => 'integer',
        'total_amount' => 'decimal:2',
        'total_refunds' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'discrepancy_amount' => 'decimal:2',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(FinEftposTerminal::class, 'terminal_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinEftposTransaction::class, 'batch_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(FinBankTransaction::class, 'bank_transaction_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeUnreconciled($query)
    {
        return $query->whereIn('status', ['closed', 'discrepancy']);
    }
}
