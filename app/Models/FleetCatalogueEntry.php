<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable choice added through a searchable "Add new" picker (service
 * types, document types, reminder titles, interval presets). Canonical people,
 * sites, vendors and Finance records never live here.
 */
class FleetCatalogueEntry extends Model
{
    protected $fillable = [
        'kind', 'label', 'normalised_label', 'value_json', 'created_by_user_id',
        'archived_at', 'archived_by_user_id',
    ];

    protected $casts = [
        'value_json' => 'array',
        'archived_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
