<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinIntercompanyTransaction extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_intercompany_transactions';

    protected $fillable = [
        'group_id',
        'from_entity_id',
        'to_entity_id',
        'transaction_date',
        'description',
        'amount',
        'from_journal_id',
        'to_journal_id',
        'status',
        'eliminated_in_run_id',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationGroup::class, 'group_id');
    }

    public function fromEntity(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationEntity::class, 'from_entity_id');
    }

    public function toEntity(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationEntity::class, 'to_entity_id');
    }

    public function fromJournal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'from_journal_id');
    }

    public function toJournal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'to_journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForGroup($query, int $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }
}
