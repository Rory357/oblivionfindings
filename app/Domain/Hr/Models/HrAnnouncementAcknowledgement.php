<?php

namespace App\Domain\Hr\Models;

use App\Domain\Hr\Services\AnnouncementReadState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAnnouncementAcknowledgement extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $announcement = HrAnnouncement::findOrFail($receipt->announcement_id);
            $receipt->read_version ??= $announcement->read_version;
            app(AnnouncementReadState::class)->preserve($announcement);
        });
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereExists(fn ($parent) => $parent->selectRaw('1')->from('hr_announcements')
            ->whereColumn('hr_announcements.id', 'hr_announcement_acknowledgements.announcement_id')
            ->whereColumn('hr_announcements.read_version', 'hr_announcement_acknowledgements.read_version'));
    }

    protected $fillable = [
        'announcement_id',
        'user_id',
        'acknowledged_by',
        'acknowledged_at',
        'read_version',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'read_version' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(HrAnnouncement::class, 'announcement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
