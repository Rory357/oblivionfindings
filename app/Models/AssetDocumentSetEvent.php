<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retained history of a document set: created, files added, details changed, archived. */
class AssetDocumentSetEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_set_id', 'asset_id', 'action', 'set_version', 'actor_user_id', 'before_json',
        'after_json', 'reason', 'request_key', 'request_fingerprint', 'occurred_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Document history is retained as recorded.'));
        static::deleting(fn () => throw new \LogicException('Document history is retained as recorded.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
