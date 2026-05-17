<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteMealInventoryItem extends Model
{
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'site_id',
        'product_id',
        'current_qty',
        'unit',
        'par_level',
        'reorder_level',
        'location_label',
        'last_counted_at',
        'notes',
    ];

    protected $casts = [
        'current_qty' => 'decimal:4',
        'par_level' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'last_counted_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MealProduct::class, 'product_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SiteMealInventoryMovement::class, 'product_id', 'product_id')
            ->where('site_id', $this->site_id ?? 0);
    }

    public function isLowStock(): bool
    {
        if ($this->reorder_level === null) {
            return false;
        }
        return (float) $this->current_qty <= (float) $this->reorder_level;
    }

    public function isBelowPar(): bool
    {
        if ($this->par_level === null) {
            return false;
        }
        return (float) $this->current_qty < (float) $this->par_level;
    }
}
