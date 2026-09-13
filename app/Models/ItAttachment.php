<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
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
    use WritesLegacyStorageContext;

    /** Everything lives on the private disk — no per-row disk column. */
    public const DISK = 'private';

    /** Upload allowlist (stored-XSS guard — no HTML/SVG/scriptables). */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,webp,gif,heic,pdf,txt,csv,doc,docx,xls,xlsx';

    /** Per-file cap, in kilobytes (10 MB). */
    public const MAX_SIZE_KB = 10240;

    /** Filename and detected content must both belong to the upload allowlist. */
    public static function uploadRules(): array
    {
        return ['file', 'max:'.self::MAX_SIZE_KB, 'mimes:'.self::ALLOWED_MIMES, 'extensions:'.self::ALLOWED_MIMES];
    }

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
        'draft_generation_uuid', 'draft_upload_uuid', 'draft_content_hash',
        'draft_storage_state', 'draft_cleanup_attempts', 'draft_cleanup_error_code',
    ];

    protected $hidden = ['draft_generation_uuid', 'draft_upload_uuid', 'draft_content_hash', 'draft_storage_state', 'draft_cleanup_attempts', 'draft_cleanup_error_code',
        'inbound_position', 'source_inbound_attachment_id', 'inbound_content_hash', 'inbound_storage_state', 'inbound_error_code', 'inbound_cleanup_attempts',
        'malware_scan_status', 'malware_scanner', 'malware_scan_attempted_at', 'malware_scanned_at'];

    protected $casts = [
        'size' => 'integer',
        'draft_cleanup_attempts' => 'integer',
        'inbound_position' => 'integer',
        'source_inbound_attachment_id' => 'integer',
        'inbound_cleanup_attempts' => 'integer',
        'malware_scanned_at' => 'datetime',
        'malware_scan_attempted_at' => 'datetime',
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
