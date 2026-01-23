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
        'original_name',
        'path',
        'mime',
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
}
