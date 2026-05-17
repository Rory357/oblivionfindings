<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SiteMealInventoryMovement extends Model
{
    public const REASONS = [
        'stocktake',
        'delivery',
        'consumption',
        'waste',
        'adjustment',
        'plan_consumption',
    ];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'product_id',
        'delta',
        'unit',
        'reason',
        'reference_type',
        'reference_id',
        'note',
        'performed_by',
        'performed_at',
    ];

    protected $casts = [
        'delta' => 'decimal:4',
        'performed_at' => 'datetime',
    ];

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
}
