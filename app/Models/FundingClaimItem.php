<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class FundingClaimItem extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'funding_claim_id',
        'billing_entry_id',
        'service_agreement_line_item_id',
        'shift_id',
        'timesheet_id',
        'description',
        'quantity',
        'unit_price',
        'total_amount',
        'service_date',
        'funding_contract_reference',
        'delivery_digest',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'service_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if ($item->getOriginal('delivery_digest')) {
                throw new LogicException('Funding-claim delivery provenance is immutable.');
            }
        });

        static::deleting(function (self $item): void {
            if ($item->delivery_digest) {
                throw new LogicException('Funding-claim delivery provenance cannot be deleted.');
            }
        });
    }

    public function fundingClaim()
    {
        return $this->belongsTo(FundingClaim::class);
    }

    public function billingEntry()
    {
        return $this->belongsTo(BillingEntry::class);
    }

    public function lineItem()
    {
        return $this->belongsTo(ServiceAgreementLineItem::class, 'service_agreement_line_item_id');
    }
}
