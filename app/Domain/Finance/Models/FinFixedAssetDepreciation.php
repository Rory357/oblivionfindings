<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinFixedAssetDepreciation extends Model
{
    use HasFactory;

    protected $table = 'fin_fixed_asset_depreciations';

    protected $fillable = [
        'fixed_asset_id',
        'depreciation_date',
        'amount',
        'accumulated_total',
        'book_value_after',
        'journal_id',
    ];

    protected $casts = [
        'depreciation_date' => 'date',
        'amount' => 'decimal:2',
        'accumulated_total' => 'decimal:2',
        'book_value_after' => 'decimal:2',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FinFixedAsset::class, 'fixed_asset_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
