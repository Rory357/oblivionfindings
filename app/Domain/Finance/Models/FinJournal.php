<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinJournal extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Finance\FinJournalFactory::new();
    }

    protected $table = 'fin_journals';

    protected $fillable = [
        'organization_id',
        'journal_number',
        'journal_date',
        'type',
        'reference',
        'description',
        'source_type',
        'source_id',
        'fiscal_period_id',
        'status',
        'posted_at',
        'posted_by',
        'reversed_by_journal_id',
        'total_amount',
        'currency_id',
        'exchange_rate',
        'base_currency_amount',
        'created_by',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'posted_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'base_currency_amount' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FinJournalLine::class, 'journal_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FinFiscalPeriod::class, 'fiscal_period_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedByJournal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(FinCurrency::class, 'currency_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->whereBetween('journal_date', [$start, $end]);
    }
}
