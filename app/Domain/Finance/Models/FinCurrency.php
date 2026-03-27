<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinCurrency extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_currencies';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'symbol',
        'decimal_places',
        'exchange_rate',
        'rate_updated_at',
        'is_base',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'exchange_rate' => 'decimal:6',
        'rate_updated_at' => 'datetime',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function fxRatesFrom(): HasMany
    {
        return $this->hasMany(FinFxRate::class, 'from_currency_id');
    }

    public function fxRatesTo(): HasMany
    {
        return $this->hasMany(FinFxRate::class, 'to_currency_id');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(FinJournal::class, 'currency_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(FinBankAccount::class, 'currency_id');
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

    public function scopeBase($query)
    {
        return $query->where('is_base', true);
    }
}
