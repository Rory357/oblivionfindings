<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Domain\Finance\Support\BankReconciliationMutationGuard;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinBankReconciliation extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_bank_reconciliations';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'statement_import_id',
        'amends_reconciliation_id',
        'statement_date',
        'statement_balance',
        'starting_balance',
        'calculated_balance',
        'status',
        'version',
        'integrity_state',
        'recovery_message',
        'completed_at',
        'completed_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'decimal:2',
        'starting_balance' => 'decimal:2',
        'calculated_balance' => 'decimal:2',
        'completed_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (! BankReconciliationMutationGuard::allowsCanonicalMutation()) {
                throw BankReconciliationConflict::generic();
            }
        });

        static::updating(function (self $reconciliation): void {
            if (! BankReconciliationMutationGuard::allowsCanonicalMutation()) {
                throw BankReconciliationConflict::generic();
            }
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinBankReconciliationLine::class, 'reconciliation_id')
            ->where('is_matched', true);
    }

    public function matchHistory(): HasMany
    {
        return $this->hasMany(FinBankReconciliationLine::class, 'reconciliation_id');
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(FinBankStatementImport::class, 'statement_import_id');
    }

    public function amendedReconciliation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amends_reconciliation_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(self::class, 'amends_reconciliation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FinBankReconciliationEvent::class, 'reconciliation_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
