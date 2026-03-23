<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'timesheet_id',
        'shift_id',
        'client_id',
        'staff_id',
        'service_agreement_id',
        'line_item_id',
        'service_date',
        'hours',
        'rate',
        'amount',
        'rate_type',
        'status',
        'billing_period_start',
        'billing_period_end',
        'notes',
    ];

    protected $casts = [
        'service_date' => 'date',
        'hours' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function serviceAgreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class);
    }
}
