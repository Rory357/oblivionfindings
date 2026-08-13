<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientFund extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'client_id',
        'fund_name',
        'fund_type',
        'currency_code',
        'balance',
        'available_balance',
        'governance_review_status',
        'governance_review_reason',
        'reconciliation_status',
        'reconciliation_difference',
        'reconciliation_details',
        'reconciled_at',
        'low_balance_threshold',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'overdraft_limit' => 'decimal:2',
        'overdraft_authorized_at' => 'datetime',
        'reconciliation_difference' => 'decimal:2',
        'reconciliation_details' => 'array',
        'reconciled_at' => 'datetime',
        'low_balance_threshold' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClientFund $fund): void {
            $fund->currency_code ??= strtoupper((string) config('finance.client_funds.currency', 'NZD'));
            $fund->available_balance ??= $fund->balance ?? '0.00';
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions()
    {
        return $this->hasMany(ClientFundTransaction::class);
    }

    public function overdraftAuthorizer()
    {
        return $this->belongsTo(User::class, 'overdraft_authorized_by');
    }
}
