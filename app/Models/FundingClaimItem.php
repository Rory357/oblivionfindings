<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundingClaimItem extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'organization_id',
        'funding_claim_id',
        'service_agreement_line_item_id',
        'shift_id',
        'timesheet_id',
        'description',
        'quantity',
        'unit_price',
        'total_amount',
        'service_date',
        'funding_contract_reference',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'service_date' => 'date',
    ];

    public function fundingClaim()
    {
        return $this->belongsTo(FundingClaim::class);
    }

    public function lineItem()
    {
        return $this->belongsTo(ServiceAgreementLineItem::class, 'service_agreement_line_item_id');
    }
}
