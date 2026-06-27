<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrInterview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Interview invite (and day-before reminder) with a calendar .ics attachment.
 * Sent to the candidate (on-demand) and each panellist (User). Times are stored
 * UTC and emitted as UTC (Z) in the VEVENT.
 */
class InterviewInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrInterview $interview,
        private string $candidateName,
        private string $roleTitle,
        private bool $isReminder = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        $start = $this->interview->scheduled_at;
        $when = $start ? $start->copy()->timezone($tz)->format('l j F Y, g:i A') : 'a time to be confirmed';
        $type = ucwords(str_replace('_', ' ', (string) $this->interview->interview_type));
        $subjectPrefix = $this->isReminder ? 'Reminder: interview tomorrow' : 'Interview scheduled';

        $mail = (new MailMessage)
            ->subject("{$subjectPrefix} — {$this->roleTitle}")
            ->greeting('Kia ora,')
            ->line($this->isReminder
                ? "This is a friendly reminder of the upcoming interview for **{$this->roleTitle}**."
                : "An interview has been scheduled for **{$this->roleTitle}** with **{$this->candidateName}**.")
            ->line("**When:** {$when} ({$tz})")
            ->line("**Type:** {$type}");

        if ($this->interview->location) {
            $mail->line("**Where:** {$this->interview->location}");
        }
        if ($this->interview->duration_minutes) {
            $mail->line("**Duration:** {$this->interview->duration_minutes} minutes");
        }

        return $mail
            ->line('A calendar invite is attached.')
            ->attachData($this->buildIcs(), 'interview.ics', ['mime' => 'text/calendar; charset=UTF-8; method=REQUEST']);
    }

    private function buildIcs(): string
    {
        $start = $this->interview->scheduled_at ?: Carbon::now();
        $end = $start->copy()->addMinutes((int) ($this->interview->duration_minutes ?: 45));
        $stamp = fn (Carbon $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $summary = $this->escape("Interview · {$this->roleTitle} · {$this->candidateName}");
        $location = $this->escape((string) $this->interview->location);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Oblivion Findings//Recruitment//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:interview-'.$this->interview->id.'@oblivionfindings.calendar',
            'DTSTAMP:'.$stamp(Carbon::now()),
            'DTSTART:'.$stamp($start),
            'DTEND:'.$stamp($end),
            'SUMMARY:'.$summary,
        ];
        if ($location !== '') {
            $lines[] = 'LOCATION:'.$location;
        }
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }
}
