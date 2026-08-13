<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Domain\Finance\Support\BankReconciliationMutationGuard;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinBankStatementImport extends Model
{
    use AuditableChanges;

    protected $table = 'fin_bank_statement_imports';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'file_fingerprint',
        'original_filename',
        'format',
        'status',
        'row_count',
        'imported_count',
        'skipped_count',
        'imported_by',
        'started_at',
        'completed_at',
        'failed_at',
        'failure_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (! BankReconciliationMutationGuard::allowsCanonicalMutation()) {
                throw BankReconciliationConflict::generic();
            }
        });
        static::updating(function (): void {
            if (! BankReconciliationMutationGuard::allowsCanonicalMutation()) {
                throw BankReconciliationConflict::generic();
            }
        });
        static::deleting(fn () => throw new BankReconciliationConflict('Statement import history is immutable.'));
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinBankTransaction::class, 'statement_import_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
