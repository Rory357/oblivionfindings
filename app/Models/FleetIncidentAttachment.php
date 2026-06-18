<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fleet & Asset Incidents redesign — Step 1 (Gap F3). Evidence attached to a
 * fleet/asset incident (scene/damage photos, dashcam, TCR/insurance PDFs).
 */
class FleetIncidentAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'fleet_incident_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
        'kind',
        'notes',
        'alt_text',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(FleetIncident::class, 'fleet_incident_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
