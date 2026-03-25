<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MileageClaim extends Model
{
    protected $table = 'mileage_claims';

    protected $fillable = [
        'organization_id',
        'user_id',
        'shift_id',
        'client_id',
        'claim_date',
        'origin',
        'destination',
        'distance_km',
        'rate_per_km',
        'amount',
        'purpose',
        'status',
        'submitted_at',
        'approved_by',
        'approved_at',
        'receipt_path',
    ];

    protected $casts = [
        'claim_date' => 'date',
        'distance_km' => 'decimal:2',
        'rate_per_km' => 'decimal:4',
        'amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
