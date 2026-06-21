<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientIncidentAttachment extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'incident_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'mime_type',
        'size',
        'portal_visible',
        'notes',
    ];

    protected $casts = [
        'portal_visible' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'incident_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): ?string
    {
        // Files now live on the PRIVATE disk (no public /storage URL). Preview/download
        // goes through the authenticated, IDOR-guarded route which streams with
        // nosniff + CSP sandbox (see ServesPrivateAttachments).
        return $this->path
            ? route('incidents.attachments.download', [$this->incident_id, $this->id])
            : null;
    }
}
