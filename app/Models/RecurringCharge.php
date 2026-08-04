<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurringCharge extends Model
{
    use SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $table = 'recurring_charges';

    protected $fillable = [
        'client_id',
        'service_agreement_id',
        'name',
        'description',
        'amount',
        'frequency',
        'day_of_week',
        'day_of_month',
        'starts_at',
        'ends_at',
        'is_active',
        'last_charged_at',
        'next_charge_at',
        'price_book_item_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'is_active' => 'boolean',
        'last_charged_at' => 'date',
        'next_charge_at' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceAgreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
