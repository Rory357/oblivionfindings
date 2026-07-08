<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A file on the helpdesk: attached to a ticket (raised with evidence), a
 * thread comment, or (later) a KB article. Stored on the PRIVATE disk —
 * download only via the authorised it.attachments.download route, streamed
 * through ServesPrivateAttachments (CSP sandbox, no public /storage URL).
 */
class ItAttachment extends Model
{
    /** Everything lives on the private disk — no per-row disk column. */
    public const DISK = 'private';

    /** Upload allowlist (stored-XSS guard — no HTML/SVG/scriptables). */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,webp,gif,heic,pdf,txt,csv,doc,docx,xls,xlsx';

    /** Per-file cap, in kilobytes (10 MB). */
    public const MAX_SIZE_KB = 10240;

    protected $fillable = [
        'tenant_id',
        'attachable_type',
        'attachable_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
