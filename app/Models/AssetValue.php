<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetValue extends Model
{
    protected $fillable = [
        'asset_id',
        'purchase_cost',
        'current_value',
        'replacement_value',
        'depreciation_model',
        'depreciation_rate',
        'insurance_policy_id',
    ];

    protected $casts = [
        'purchase_cost' => 'decimal:2',
        'current_value' => 'decimal:2',
        'replacement_value' => 'decimal:2',
        'depreciation_rate' => 'decimal:3',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
