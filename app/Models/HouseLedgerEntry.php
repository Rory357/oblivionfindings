<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HouseLedgerEntry extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'house_ledger_id',
        'entry_type',
        'category',
        'description',
        'reference',
        'amount',
        'running_balance',
        'entry_date',
        'recorded_by',
        'approved_by',
        'approved_at',
        'notes',
        'attachments',
        'journal_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'entry_date' => 'date',
        'approved_at' => 'datetime',
        'attachments' => 'array',
    ];

    // Relationships

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(HouseLedger::class, 'house_ledger_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
