<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinAccount extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_accounts';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'type',
        'sub_type',
        'parent_id',
        'funding_stream_id',
        'is_system',
        'is_active',
        'opening_balance',
        'description',
        'gst_applicable',
        'default_tax_rate_id',
        'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'gst_applicable' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }

    public function defaultTaxRate(): BelongsTo
    {
        return $this->belongsTo(FinTaxRate::class, 'default_tax_rate_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(FinJournalLine::class, 'account_id');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('fin_accounts.is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('fin_accounts.type', $type);
    }

    public function scopeBySubType($query, string $subType)
    {
        return $query->where('fin_accounts.sub_type', $subType);
    }

    /**
     * Calculate the account balance from journal lines.
     * For asset/expense accounts: debits - credits
     * For liability/equity/revenue accounts: credits - debits
     */
    public function getBalance(): float
    {
        $totals = $this->journalLines()
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debits, COALESCE(SUM(credit), 0) as total_credits')
            ->first();

        $totalDebits = (float) $totals->total_debits;
        $totalCredits = (float) $totals->total_credits;

        if (in_array($this->type, ['asset', 'expense'])) {
            return $totalDebits - $totalCredits + (float) $this->opening_balance;
        }

        return $totalCredits - $totalDebits + (float) $this->opening_balance;
    }
}
