<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientFundTransaction extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'client_fund_id',
        'idempotency_key',
        'transaction_type',
        'amount',
        'running_balance',
        'description',
        'reference',
        'category',
        'transaction_date',
        'recorded_by',
        'receipt_path',
        'journal_id',
        'gl_posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'transaction_date' => 'date',
        'gl_posted_at' => 'datetime',
    ];

    public function fund()
    {
        return $this->belongsTo(ClientFund::class, 'client_fund_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function journal()
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
