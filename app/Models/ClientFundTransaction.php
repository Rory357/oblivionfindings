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
        'client_id',
        'site_id',
        'destination_fund_id',
        'counterpart_transaction_id',
        'reversal_of_id',
        'idempotency_key',
        'status',
        'transaction_type',
        'amount',
        'currency_code',
        'running_balance',
        'description',
        'reference',
        'source_type',
        'source_id',
        'category',
        'transaction_date',
        'recorded_by',
        'approval_required',
        'requested_at',
        'approved_by',
        'approved_at',
        'approval_reason',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'reversal_reason',
        'balance_effect_applied_at',
        'receipt_path',
        'journal_id',
        'gl_posted_at',
        'posting_attempted_at',
        'posting_failed_at',
        'posting_failure_code',
        'posting_failure_message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'transaction_date' => 'date',
        'approval_required' => 'boolean',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'balance_effect_applied_at' => 'datetime',
        'gl_posted_at' => 'datetime',
        'posting_attempted_at' => 'datetime',
        'posting_failed_at' => 'datetime',
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

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function destinationFund()
    {
        return $this->belongsTo(ClientFund::class, 'destination_fund_id');
    }

    public function counterpart()
    {
        return $this->belongsTo(self::class, 'counterpart_transaction_id');
    }

    public function originalTransaction()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversalTransaction()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
