<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMealShoppingListItem extends Model
{
    public const SOURCES = ['meal_plan', 'restock_to_par', 'manual'];

    protected $fillable = [
        'list_id',
        'product_id',
        'free_text_name',
        'needed_qty',
        'unit',
        'source',
        'source_meta',
        'received_qty',
        'estimated_cost_cents',
        'is_checked',
        'notes',
    ];

    protected $casts = [
        'needed_qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
        'source_meta' => 'array',
        'is_checked' => 'boolean',
        'estimated_cost_cents' => 'integer',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(SiteMealShoppingList::class, 'list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MealProduct::class, 'product_id');
    }

    public function displayName(): string
    {
        if ($this->product) {
            return $this->product->name;
        }
        return $this->free_text_name ?? 'Unnamed item';
    }
}
