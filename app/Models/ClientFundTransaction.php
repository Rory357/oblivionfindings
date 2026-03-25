<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientFundTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'client_fund_id',
        'transaction_type',
        'amount',
        'running_balance',
        'description',
        'reference',
        'category',
        'transaction_date',
        'recorded_by',
        'receipt_path',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function fund()
    {
        return $this->belongsTo(ClientFund::class, 'client_fund_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
