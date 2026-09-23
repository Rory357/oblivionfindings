<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDocument extends Model
{
    use AuditableChanges;

    /** Private vehicle uploads become available only after a clean scan. */
    public const STATE_AVAILABLE = 'available';

    /** Vehicle files uploaded before scanning existed; kept as they were. */
    public const STATE_LEGACY = 'legacy_unverified';

    protected $fillable = [
        'asset_id',
        'uploaded_by_user_id',
        'title',
        'category',
        'version',
        'effective_date',
        'expiry_date',
        'notes',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        // PKG-02B private versioned vehicle files. `revision` is the
        // document set's file generation this file was uploaded in.
        'document_set_id',
        'revision',
        'source_type',
        'source_id',
        'state',
        'sha256',
        'detected_mime',
        'scan_disposition',
        'scanner',
        'scan_failure_code',
        'scan_attempted_at',
        'scanned_at',
        'request_key',
        'request_fingerprint',
        'archived_at',
        'archived_by_user_id',
        'archive_reason',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'revision' => 'integer',
        'scan_attempted_at' => 'datetime',
        'scanned_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function documentSet(): BelongsTo
    {
        return $this->belongsTo(AssetDocumentSet::class, 'document_set_id');
    }

    /** True when the bytes may be opened: a clean private upload, or a pre-scanning file. */
    public function isOpenable(): bool
    {
        return in_array($this->state, [self::STATE_AVAILABLE, self::STATE_LEGACY], true)
            || ($this->state === null && $this->document_set_id === null);
    }
}
