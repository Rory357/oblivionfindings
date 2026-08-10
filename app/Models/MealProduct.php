<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MealProduct extends Model
{
    use AuditableChanges, SoftDeletes, WritesLegacyStorageContext;

    protected $fillable = [
        'name',
        'category',
        'default_unit',
        'pack_size',
        'pack_unit',
        'cost_per_unit_cents',
        'currency',
        'is_active',
        'barcode',
        'external_refs',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'external_refs' => 'array',
        'pack_size' => 'decimal:4',
        'cost_per_unit_cents' => 'integer',
    ];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MealDietaryTag::class, 'meal_product_tag', 'product_id', 'tag_id');
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(MealRecipeIngredient::class, 'product_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(SiteMealInventoryItem::class, 'product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
