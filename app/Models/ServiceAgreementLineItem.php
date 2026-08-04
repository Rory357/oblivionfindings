<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAgreementLineItem extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'service_agreement_id',
        'item_number',
        'description',
        'unit_price',
        'quantity',
        'unit',
        'budget_allocated',
        'budget_used',
        'category',
        'funding_contract_reference',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'budget_allocated' => 'decimal:2',
        'budget_used' => 'decimal:2',
    ];

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class);
    }
}
