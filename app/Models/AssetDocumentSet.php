<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document (a policy, agreement or certificate) made of one or more
 * files. The set owns the shared details, its expiry and at most one renewal
 * reminder; replacing files keeps the set, so the reminder identity survives.
 */
class AssetDocumentSet extends Model
{
    protected $fillable = [
        'asset_id', 'category', 'reference', 'document_date', 'expires_on', 'source_type', 'source_id',
        'lock_version', 'current_revision', 'created_by_user_id', 'archived_at', 'archived_by_user_id',
        'archive_reason', 'request_key', 'request_fingerprint', 'legacy_backfill',
    ];

    protected $casts = [
        'document_date' => 'date',
        'expires_on' => 'date',
        'archived_at' => 'datetime',
        'lock_version' => 'integer',
        'current_revision' => 'integer',
        'legacy_backfill' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(AssetDocument::class, 'document_set_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetDocumentSetEvent::class, 'document_set_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
