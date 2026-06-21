<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PPE redesign — evidence attached to a PPE allocation (fit-test records per
 * AS/NZS 1715, signed acknowledgement forms, training certificates).
 */
class PpeAllocationAttachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ppe_allocation_id',
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

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PpeAllocation::class, 'ppe_allocation_id');
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
