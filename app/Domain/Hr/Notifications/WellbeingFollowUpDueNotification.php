<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrWellbeingCheckin;
use Illuminate\Notifications\Notification;

/**
 * One-time nudge to the manager who recorded a wellbeing check-in when its
 * follow-up date arrives and no follow-up has been recorded. Deduped via
 * hr_wellbeing_checkins.follow_up_reminder_sent_at.
 */
class WellbeingFollowUpDueNotification extends Notification
{
    public function __construct(
        private HrWellbeingCheckin $checkin,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $staffName = $this->checkin->staff?->name ?? 'a staff member';

        return [
            'type' => 'wellbeing_follow_up_due',
            'title' => 'Wellbeing follow-up due',
            'message' => "Your wellbeing check-in with {$staffName} was due a follow-up on "
                . ($this->checkin->follow_up_date?->format('d M Y') ?? 'today')
                . '. Record the follow-up or schedule another check-in.',
            'checkin_id' => $this->checkin->id,
            'staff_user_id' => $this->checkin->staff_user_id,
            'follow_up_date' => $this->checkin->follow_up_date?->toDateString(),
            'action_url' => '/hr/wellbeing',
        ];
    }
}
