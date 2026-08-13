<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Services\Catering\ImmutableInventoryMovementBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class SiteMealInventoryMovement extends Model
{
    use WritesLegacyStorageContext;

    public const MEAL_SERVICE_ACTION_SERVE = 'serve';

    public const MEAL_SERVICE_ACTION_UNSERVE = 'unserve';

    public const REASONS = [
        'stocktake',
        'delivery',
        'consumption',
        'waste',
        'adjustment',
        'plan_consumption',
    ];

    protected $fillable = [
        'site_id',
        'product_id',
        'delta',
        'unit',
        'reason',
        'reference_type',
        'reference_id',
        'meal_service_key',
        'meal_service_action',
        'meal_recipe_id',
        'meal_recipe_ingredient_ids',
        'reversal_of_id',
        'note',
        'performed_by',
        'performed_at',
    ];

    protected $casts = [
        'delta' => 'decimal:4',
        'meal_recipe_ingredient_ids' => 'array',
        'performed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory movements are immutable.'));
    }

    public function newEloquentBuilder($query): ImmutableInventoryMovementBuilder
    {
        return new ImmutableInventoryMovementBuilder($query);
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Inventory movements are immutable.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Inventory movements are immutable.');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MealProduct::class, 'product_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'performed_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
