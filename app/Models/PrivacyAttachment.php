<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Privacy command-centre redesign — Step 1.
 *
 * Polymorphic evidence/document attached to any privacy record (data subject
 * request, breach, legal hold, retention policy, DPIA). Per-file note +
 * sensitivity flag; sensitive files are download-gated. Mirrors the
 * Safeguarding attachment shape.
 */
class PrivacyAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
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

    public function attachable(): MorphTo
    {
        return $this->morphTo();
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
