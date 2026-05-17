<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealRecipeIngredient extends Model
{
    protected $fillable = [
        'recipe_id',
        'product_id',
        'free_text_name',
        'quantity',
        'unit',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(MealRecipe::class, 'recipe_id');
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
        return $this->free_text_name ?? 'Unnamed ingredient';
    }
}
