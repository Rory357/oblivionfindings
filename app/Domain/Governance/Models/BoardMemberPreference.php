<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardMemberPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'board_member_id',
        'timezone',
        'quiet_hours_start',
        'quiet_hours_end',
        'digest_day',
        'digest_time',
        'digest_enabled',
        'email_meeting_reminders',
        'email_action_items',
        'email_compliance_alerts',
        'email_resolutions',
        'urgent_contact_method',
        'mobile_number',
        'preferred_format',
    ];

    protected $casts = [
        'digest_enabled' => 'boolean',
        'email_meeting_reminders' => 'boolean',
        'email_action_items' => 'boolean',
        'email_compliance_alerts' => 'boolean',
        'email_resolutions' => 'boolean',
    ];

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function isQuietHours(): bool
    {
        $now = now()->timezone($this->timezone);
        $currentTime = $now->format('H:i');
        
        // Handle overnight quiet hours (e.g., 22:00 - 07:00)
        if ($this->quiet_hours_start > $this->quiet_hours_end) {
            return $currentTime >= $this->quiet_hours_start || $currentTime < $this->quiet_hours_end;
        }
        
        return $currentTime >= $this->quiet_hours_start && $currentTime < $this->quiet_hours_end;
    }

    public function canSendEmail(string $type): bool
    {
        if ($this->isQuietHours()) {
            return false;
        }

        return match($type) {
            'meeting_reminder' => $this->email_meeting_reminders,
            'action_item' => $this->email_action_items,
            'compliance_alert' => $this->email_compliance_alerts,
            'resolution' => $this->email_resolutions,
            default => true,
        };
    }

    public function getNextDigestDateTime(): \Carbon\Carbon
    {
        $dayMap = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];
        
        $targetDay = $dayMap[$this->digest_day] ?? 1;
        $now = now()->timezone($this->timezone);
        $currentDay = $now->dayOfWeek;
        
        $daysUntilDigest = ($targetDay - $currentDay + 7) % 7;
        if ($daysUntilDigest === 0 && $now->format('H:i') > $this->digest_time) {
            $daysUntilDigest = 7;
        }
        
        return $now->addDays($daysUntilDigest)
            ->setTimeFromTimeString($this->digest_time);
    }
}
