<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable publication evidence belonging to the canonical catalogue item. */
final class ItCatalogVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['catalog_item_id', 'version', 'contract', 'provenance', 'published_by'];

    protected $casts = ['version' => 'integer', 'contract' => 'array'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Catalogue publications are immutable.'));
        self::deleting(fn () => throw new LogicException('Catalogue publications are immutable.'));
    }
}
