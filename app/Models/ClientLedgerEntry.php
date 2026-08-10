<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientLedgerEntry extends Model
{
    use AuditableChanges, SoftDeletes, WritesLegacyStorageContext;

    protected $fillable = [
        'client_id',
        'site_id',
        'type',
        'category',
        'direction',
        'amount',
        'description',
        'reference',
        'journal_id',
        'posts_to_gl',
        'entry_date',
        'recorded_by',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'posts_to_gl' => 'boolean',
        'entry_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForPeriod($query, $from, $to)
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    public function scopeInflows($query)
    {
        return $query->where('direction', 'inflow');
    }

    public function scopeOutflows($query)
    {
        return $query->where('direction', 'outflow');
    }

    public function scopeGlPosted($query)
    {
        return $query->whereNotNull('journal_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    public function isInflow(): bool
    {
        return $this->direction === 'inflow';
    }

    public function isOutflow(): bool
    {
        return $this->direction === 'outflow';
    }

    /**
     * Signed amount: positive for inflows, negative for outflows.
     */
    public function signedAmount(): string
    {
        return $this->isOutflow()
            ? '-' . (string) $this->amount
            : (string) $this->amount;
    }
}
