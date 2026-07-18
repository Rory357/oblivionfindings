<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class ItCatalogSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'catalog_item_id',
        'requester_user_id',
        'schema_version',
        'schema_snapshot',
        'submitted_values',
        'idempotency_key',
        'result_type',
        'result_id',
        'submitted_at',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'schema_snapshot' => 'array',
        'submitted_values' => 'array',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Catalogue submissions are immutable.');
        });
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ItCatalogItem::class, 'catalog_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function result(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'result_type', 'result_id');
    }
}
