<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinPettyCashFund extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_petty_cash_funds';

    protected $fillable = [
        'organization_id',
        'name',
        'gl_account_id',
        'float_amount',
        'current_balance',
        'custodian_user_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'float_amount' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_account_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinPettyCashTransaction::class, 'petty_cash_fund_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
