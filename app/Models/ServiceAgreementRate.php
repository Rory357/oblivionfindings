<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAgreementRate extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'service_agreement_id',
        'rate_type',
        'rate',
        'unit',
        'effective_from',
        'effective_to',
        'notes',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class);
    }
}
