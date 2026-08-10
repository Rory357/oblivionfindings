<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MealDietaryTag extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;

    protected $fillable = [
        'key',
        'label',
        'kind',
        'severity',
        'color',
        'description',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(MealProduct::class, 'meal_product_tag', 'tag_id', 'product_id');
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(MealRecipe::class, 'meal_recipe_tag', 'tag_id', 'recipe_id');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_meal_dietary_tag', 'tag_id', 'client_id')
            ->withPivot('notes')
            ->withTimestamps();
    }

    public function scopeAllergens($query)
    {
        return $query->where('kind', 'allergen');
    }

    public function scopeDietary($query)
    {
        return $query->where('kind', 'dietary');
    }
}
