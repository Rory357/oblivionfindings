<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MealRecipe extends Model
{
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'serves_default',
        'prep_minutes',
        'cook_minutes',
        'instructions',
        'image_path',
        'is_active',
        'scope',
        'site_id',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'serves_default' => 'integer',
        'prep_minutes' => 'integer',
        'cook_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (MealRecipe $recipe) {
            if (empty($recipe->slug)) {
                $recipe->slug = static::generateSlug($recipe->name, $recipe->tenant_id);
            }
        });
    }

    public static function generateSlug(string $name, ?int $tenantId): string
    {
        $base = Str::slug($name) ?: 'recipe';
        $slug = $base;
        $i = 2;
        while (static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(MealRecipeIngredient::class, 'recipe_id')->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MealDietaryTag::class, 'meal_recipe_tag', 'recipe_id', 'tag_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Recipes visible to a site: the shared (org-wide) library plus any
     * recipes scoped to this house. Used by the Meal Planner.
     */
    public function scopeVisibleToSite($query, int $siteId)
    {
        return $query->where(function ($q) use ($siteId) {
            $q->where('scope', 'shared')
                ->orWhere(fn ($qq) => $qq->where('scope', 'house')->where('site_id', $siteId));
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
