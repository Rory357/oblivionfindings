<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestraintEventAttachment extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    use AuditableChanges;

    protected $fillable = [
        'sha256', 'source_revision', 'removed_by', 'removed_at', 'removal_reason', 'removal_command_key', 'uploader_snapshot',
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

    protected $casts = ['removed_at' => 'immutable_datetime', 'source_revision' => 'integer', 'uploader_snapshot' => 'array'];

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
        return $this->path && ! $this->removed_at && $this->disk === 'private'
            ? route('health-safety.restraints.events.attachments.download', [$this->restraint_event_id, $this->id])
            : null;
    }
}
