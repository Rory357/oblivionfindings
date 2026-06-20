<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Injuries & RTW redesign — Step 1. Evidence attached to a workplace injury
 * (medical certificates, ACC forms, RTW clearance letters, injury photos).
 * Mirrors FleetIncidentAttachment / SafeguardingAttachment.
 */
class WorkplaceInjuryAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workplace_injury_id',
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

    public function injury(): BelongsTo
    {
        return $this->belongsTo(WorkplaceInjury::class, 'workplace_injury_id');
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
