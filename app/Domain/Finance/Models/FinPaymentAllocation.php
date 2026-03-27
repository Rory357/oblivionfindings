<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinPaymentAllocation extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_payment_allocations';

    protected $fillable = [
        'organization_id',
        'type',
        'payment_date',
        'amount',
        'allocatable_type',
        'allocatable_id',
        'source_type',
        'source_id',
        'journal_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopePayable($query)
    {
        return $query->where('type', 'payable');
    }

    public function scopeReceivable($query)
    {
        return $query->where('type', 'receivable');
    }
}
