<?php

namespace App\Models;

use App\Domain\Finance\Models\FinInvoice;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'client_id',
        'quote_number',
        'title',
        'status',
        'client_name',
        'client_email',
        'client_phone',
        'valid_until',
        'subtotal',
        'tax_amount',
        'total_amount',
        'notes',
        'terms',
        'sent_at',
        'accepted_at',
        'converted_to_agreement_id',
        'converted_to_invoice_id',
        'converted_by',
        'converted_at',
        'conversion_digest',
        'created_by',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lineItems()
    {
        return $this->hasMany(QuoteLineItem::class);
    }

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class, 'converted_to_agreement_id');
    }

    public function invoice()
    {
        return $this->belongsTo(FinInvoice::class, 'converted_to_invoice_id');
    }

    public function convertedBy()
    {
        return $this->belongsTo(User::class, 'converted_by');
    }
}
