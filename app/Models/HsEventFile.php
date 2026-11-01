<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A photo, video or document on a safety review's step (G3 board M5d). Kept as it was added: only hiding (with a
 * reason, while the review is open) changes it, and it is never deleted.
 */
class HsEventFile extends Model
{
    public const STEPS = ['assigned', 'investigated', 'implemented', 'check', 'closed'];

    public const KIND_PHOTO = 'photo';

    public const KIND_VIDEO = 'video';

    public const KIND_DOCUMENT = 'document';

    /** Decision 22: videos up to 2 minutes / 500 MB; every other file up to 20 MB. */
    public const VIDEO_MAX_SECONDS = 120;

    public const VIDEO_MAX_KB = 512000;

    public const FILE_MAX_KB = 20480;

    /** The allow-list (a tightening: nothing else is taken). */
    public const MIMES = [
        self::KIND_PHOTO => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
        self::KIND_VIDEO => ['mp4', 'mov', 'webm', 'm4v'],
        self::KIND_DOCUMENT => ['pdf', 'doc', 'docx'],
    ];

    private const FROZEN = ['hs_event_id', 'step', 'kind', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'duration_seconds',
        'description', 'uploaded_by', 'uploaded_by_name'];

    protected $fillable = [
        'hs_event_id', 'step', 'kind', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'duration_seconds',
        'description', 'uploaded_by', 'uploaded_by_name', 'hidden_at', 'hidden_by', 'hidden_reason',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'hidden_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $file): void {
            foreach (self::FROZEN as $column) {
                if ($file->isDirty($column)) {
                    throw new LogicException('A review file is kept as it was added.');
                }
            }
            if ($file->getOriginal('hidden_at') !== null) {
                throw new LogicException('A hidden review file stays hidden as it was.');
            }
        });
        static::deleting(fn () => throw new LogicException('Review files are hidden with a reason, never deleted.'));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public static function kindFor(string $extension): ?string
    {
        $extension = strtolower($extension);
        foreach (self::MIMES as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }

        return null;
    }
}
