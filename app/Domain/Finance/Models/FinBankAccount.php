<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinBankAccount extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_bank_accounts';

    protected $fillable = [
        'organization_id',
        'name',
        'bank_name',
        'account_number',
        'account_type',
        'gl_account_id',
        'opening_balance',
        'current_balance',
        'is_primary',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'account_number' => 'encrypted',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinBankTransaction::class, 'bank_account_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(FinBankReconciliation::class, 'bank_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
