<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceBookItem extends Model
{
    use HasFactory;
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'price_book_id',
        'service_code',
        'name',
        'description',
        'unit',
        'rate',
        'rate_type',
        'category',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function priceBook()
    {
        return $this->belongsTo(PriceBook::class);
    }
}
