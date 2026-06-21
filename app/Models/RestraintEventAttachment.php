<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestraintEventAttachment extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'restraint_event_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'mime_type',
        'size',
        'category',
        'notes',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(RestraintEvent::class, 'restraint_event_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): ?string
    {
        return $this->path
            ? route('health-safety.restraints.events.attachments.download', [$this->restraint_event_id, $this->id])
            : null;
    }
}
