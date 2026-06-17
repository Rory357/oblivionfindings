<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Safeguarding redesign — Step 7a (W8). Evidence attached to a concern.
 */
class SafeguardingAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'safeguarding_concern_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
        'notes',
        'is_sensitive',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
        'size' => 'integer',
    ];

    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
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
