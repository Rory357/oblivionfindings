<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'quote_id',
        'price_book_item_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'amount',
        'service_code',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function priceBookItem()
    {
        return $this->belongsTo(PriceBookItem::class);
    }
}
