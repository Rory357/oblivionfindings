<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to an HR calendar event, stored on the private disk and served
 * through the hardened {@see \App\Http\Controllers\Concerns\ServesPrivateAttachments}
 * route (mirrors {@see HrFeedAttachment}).
 */
class HrCalendarEventAttachment extends Model
{
    protected $table = 'hr_calendar_event_attachments';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(HrCalendarEvent::class, 'event_id');
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
