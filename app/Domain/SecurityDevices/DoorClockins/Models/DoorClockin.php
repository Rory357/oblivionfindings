<?php

namespace App\Domain\SecurityDevices\DoorClockins\Models;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rostered start at a Site where clock-ins from doors are on, or one badge from someone not on
 * the roster (D15). Its badge, what the person chose, the 15-minute gap check and a manager's
 * review. Not a time record: a confirmed clock-in is an attendance session. Goes at 90 days.
 *
 * @property int $id
 * @property string $kind
 * @property int $site_id
 * @property int $user_id
 * @property int|null $shift_id
 * @property string|null $answer
 * @property string|null $review
 */
final class DoorClockin extends Model
{
    public const string ROSTERED = 'rostered';

    public const string NOT_ROSTERED = 'not_rostered';

    protected $table = 'security_door_clockins';

    protected $fillable = [
        'kind', 'dedupe_key', 'day', 'site_id', 'user_id', 'shift_id', 'shift_starts_at', 'shift_ends_at',
        'event_id', 'door_id', 'method', 'badge_at', 'suggested_at', 'answer', 'answered_at', 'attendance_session_id',
        'closed_at', 'close_reason', 'reminded_at', 'manager_told_at', 'manager_told_user_ids', 'gap_checked_at',
        'review', 'reviewed_by_user_id', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'day' => 'date:Y-m-d',
        'shift_starts_at' => 'immutable_datetime',
        'shift_ends_at' => 'immutable_datetime',
        'badge_at' => 'immutable_datetime',
        'suggested_at' => 'immutable_datetime',
        'answered_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'reminded_at' => 'immutable_datetime',
        'manager_told_at' => 'immutable_datetime',
        'manager_told_user_ids' => 'array',
        'gap_checked_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];

    /** A badge suggested a clock-in that the person hasn't answered, and it's still open. */
    public function isWaiting(): bool
    {
        return $this->kind === self::ROSTERED && $this->badge_at !== null && $this->closed_at === null
            && ($this->answer === null || $this->staleClaim());
    }

    /** A tap that never finished (the request died mid-way) frees the suggestion again after two minutes. */
    public function staleClaim(): bool
    {
        return $this->answer === 'claiming' && $this->updated_at !== null && $this->updated_at->lt(now()->subMinutes(2));
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /** @return BelongsTo<AccessEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AccessEvent::class, 'event_id');
    }

    /** @return BelongsTo<AccessDoor, $this> */
    public function door(): BelongsTo
    {
        return $this->belongsTo(AccessDoor::class, 'door_id');
    }

    /** @return BelongsTo<HrAttendanceSession, $this> */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(HrAttendanceSession::class, 'attendance_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
