<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAnnouncementAttachment extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        $revise = fn (self $attachment) => HrAnnouncement::whereKey($attachment->announcement_id)->increment('read_version');
        static::created($revise);
        static::updated($revise);
        static::deleted($revise);
    }

    protected $fillable = [
        'announcement_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(HrAnnouncement::class, 'announcement_id');
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
