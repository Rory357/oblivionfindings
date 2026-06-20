<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
        $disk = $this->disk ?: 'public';

        return $this->path ? Storage::disk($disk)->url($this->path) : null;
    }
}
