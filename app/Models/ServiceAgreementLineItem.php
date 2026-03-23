<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAgreementLineItem extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'organization_id',
        'service_agreement_id',
        'item_number',
        'description',
        'unit_price',
        'quantity',
        'unit',
        'budget_allocated',
        'budget_used',
        'category',
        'ndis_line_item_code',
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
