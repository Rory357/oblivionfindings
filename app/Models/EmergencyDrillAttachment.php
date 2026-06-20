<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Emergency Drills redesign — evidence attached to a drill (sign-in sheets,
 * assembly-point/roll-call photos, FENZ evacuation-scheme report PDFs).
 */
class EmergencyDrillAttachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'emergency_drill_id',
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

    public function drill(): BelongsTo
    {
        return $this->belongsTo(EmergencyDrill::class, 'emergency_drill_id');
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
