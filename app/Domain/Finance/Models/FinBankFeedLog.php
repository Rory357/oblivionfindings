<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinBankFeedLog extends Model
{
    use HasFactory;

    protected $table = 'fin_bank_feed_logs';

    protected $fillable = [
        'bank_feed_id',
        'synced_at',
        'status',
        'transactions_fetched',
        'transactions_imported',
        'transactions_skipped',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'transactions_fetched' => 'integer',
        'transactions_imported' => 'integer',
        'transactions_skipped' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function bankFeed(): BelongsTo
    {
        return $this->belongsTo(FinBankFeed::class, 'bank_feed_id');
    }
}
