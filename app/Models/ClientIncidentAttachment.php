<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
        $disk = $this->disk ?: 'public';
        return $this->path ? Storage::disk($disk)->url($this->path) : null;
    }
}
