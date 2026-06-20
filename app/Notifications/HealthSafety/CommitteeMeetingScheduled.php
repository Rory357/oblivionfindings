<?php

namespace App\Notifications\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to meeting attendees (forAttendee=true) and, separately, to all other
 * workers rostered at the committee's site (forAttendee=false) — satisfying the
 * HSWA duty to notify all workers of upcoming committee meetings and give them a
 * reasonable opportunity to provide input.
 *
 * Attendees receive database + email (they have a specific obligation to attend);
 * the wider workforce receives an in-app (database) notice only, so a large site
 * is not emailed for every meeting.
 */
class CommitteeMeetingScheduled extends Notification
{
    use Queueable;

    public function __construct(
        public HsCommitteeMeeting $meeting,
        public ?HsCommittee $committee,
        public bool $forAttendee = true,
    ) {}

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return $this->forAttendee ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->meeting->scheduled_at?->format('D j M Y, g:ia');
        $name = $this->committee?->name ?? 'H&S committee';

        return (new MailMessage)
            ->subject("You're invited: {$name} meeting")
            ->line("{$name} meeting — {$when}".($this->meeting->location ? " at {$this->meeting->location}" : ''))
            ->line('You are listed as an attendee.')
            ->action('View meeting', url('/health-safety/worker-participation?tab=meetings&meeting='.$this->meeting->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hs_committee_meeting',
            'for' => $this->forAttendee ? 'attendee' : 'worker_notice',
            'meeting_id' => $this->meeting->id,
            'committee' => $this->committee?->name,
            'scheduled_at' => $this->meeting->scheduled_at,
            'title' => $this->forAttendee
                ? ('Committee meeting: '.($this->committee?->name ?? 'H&S'))
                : ('H&S meeting notice: '.($this->committee?->name ?? 'committee')),
            'message' => $this->forAttendee
                ? 'You are invited to an upcoming H&S committee meeting.'
                : 'An H&S committee meeting is coming up — you are welcome to raise items for the agenda with your H&S representative.',
            'link' => '/health-safety/worker-participation?tab=meetings&meeting='.$this->meeting->id,
        ];
    }
}
