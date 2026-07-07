<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinFxRate extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_fx_rates';

    protected $fillable = [
        'organization_id',
        'from_currency_id',
        'to_currency_id',
        'rate',
        'effective_date',
        'source',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'effective_date' => 'date',
    ];

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(FinCurrency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(FinCurrency::class, 'to_currency_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeForPair($query, int $from, int $to)
    {
        return $query->where('from_currency_id', $from)->where('to_currency_id', $to);
    }

    public function scopeLatestForDate($query, string $date)
    {
        return $query->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->limit(1);
    }
}
