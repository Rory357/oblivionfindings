<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMealPlanEntry extends Model
{
    use AuditableChanges;

    public const MEAL_SLOTS = [
        'breakfast',
        'morning_tea',
        'lunch',
        'afternoon_tea',
        'dinner',
        'supper',
    ];

    public const SOURCE_TYPES = ['recipe', 'ad_hoc', 'takeaway'];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'plan_date',
        'meal_slot',
        'source_type',
        'recipe_id',
        'ad_hoc_name',
        'takeaway_vendor',
        'takeaway_cost_cents',
        'takeaway_reference',
        'servings',
        'notes',
        'client_ids',
        'served_at',
        'served_by',
        'created_by',
        'allergen_override_reason',
        'allergen_override_by',
        'allergen_override_at',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'client_ids' => 'array',
        'servings' => 'integer',
        'served_at' => 'datetime',
        'allergen_override_at' => 'datetime',
        'takeaway_cost_cents' => 'integer',
    ];

    public function isTakeaway(): bool
    {
        return $this->source_type === 'takeaway';
    }

    public function hasAllergenOverride(): bool
    {
        return $this->allergen_override_at !== null;
    }

    public function allergenOverrideBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'allergen_override_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(MealRecipe::class, 'recipe_id');
    }

    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'served_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function displayName(): string
    {
        if ($this->recipe) {
            return $this->recipe->name;
        }
        return $this->ad_hoc_name ?? 'Untitled meal';
    }
}
