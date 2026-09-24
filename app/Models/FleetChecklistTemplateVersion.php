<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published version of a vehicle checklist. Versions are immutable: a
 * change publishes the next version, and each check records the version it
 * was answered against.
 */
class FleetChecklistTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    public const ASSIGNMENTS = ['all_vehicles', 'accessible_vehicles', 'vehicle'];

    public const SOURCE_LIBRARY = 'library';

    public const SOURCE_EXISTING = 'existing_template';

    protected $fillable = [
        'template_id',
        'version',
        'name',
        'use_label',
        'assignment',
        'assignment_asset_id',
        'evidence_required',
        'items',
        'items_sha256',
        'source',
        'published_by_user_id',
        'published_at',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'version' => 'integer',
        'evidence_required' => 'boolean',
        'items' => 'array',
        'published_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistTemplate::class, 'template_id');
    }

    public function assignmentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'assignment_asset_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
