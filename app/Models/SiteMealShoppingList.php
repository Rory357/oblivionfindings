<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteMealShoppingList extends Model
{
    use AuditableChanges;
    use WritesLegacyStorageContext;

    public const STATUSES = ['draft', 'ordered', 'received', 'cancelled'];

    protected $fillable = [
        'site_id',
        'status',
        'covers_from',
        'covers_to',
        'generated_at',
        'generated_by',
        'provider_key',
        'provider_order_ref',
        'ordered_at',
        'received_at',
        'notes',
    ];

    protected $casts = [
        'covers_from' => 'date',
        'covers_to' => 'date',
        'generated_at' => 'datetime',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SiteMealShoppingListItem::class, 'list_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'generated_by');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['ordered', 'received', 'cancelled'], true);
    }
}
