<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class FinPaymentRun extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Finance\FinPaymentRunFactory::new();
    }

    protected $table = 'fin_payment_runs';

    protected $fillable = [
        'organization_id',
        'run_number',
        'bank_account_id',
        'status',
        'payment_date',
        'total_amount',
        'item_count',
        'approved_by',
        'approved_at',
        'processed_at',
        'processed_by',
        'file_path',
        'journal_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'item_count' => 'integer',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinPaymentRunItem::class, 'payment_run_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function externalSettlement(): MorphOne
    {
        return $this->morphOne(FinExternalSettlement::class, 'source')
            ->where('purpose', 'vendor_payment_run')
            ->latestOfMany('attempt_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
