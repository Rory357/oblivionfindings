<?php

namespace App\Notifications\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Daily digest sent to privacy officers when access/correction requests are
 * overdue or due soon, or when a notifiable breach still requires notification
 * to the Office of the Privacy Commissioner. Recurs daily until each item is
 * actioned (the underlying queries drop the item once it is resolved/notified).
 */
class PrivacyDeadlineDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $overdueRefs
     * @param  list<string>  $dueSoonRefs
     */
    public function __construct(
        private int $overdueCount,
        private int $dueSoonCount,
        private int $breachOpcCount,
        private int $breachSubjectCount,
        private array $overdueRefs = [],
        private array $dueSoonRefs = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Privacy deadlines need attention')
            ->greeting('Privacy compliance summary');

        if ($this->overdueCount > 0) {
            $mail->line("**{$this->overdueCount}** access/correction request(s) are overdue — past the 20-working-day Privacy Act 2020 deadline.");
            if ($this->overdueRefs !== []) {
                $mail->line('Overdue: '.implode(', ', $this->overdueRefs));
            }
        }

        if ($this->dueSoonCount > 0) {
            $mail->line("**{$this->dueSoonCount}** request(s) fall due within the next few working days.");
        }

        if ($this->breachOpcCount > 0) {
            $mail->line("**{$this->breachOpcCount}** notifiable breach(es) still require notification to the Office of the Privacy Commissioner.");
        }

        if ($this->breachSubjectCount > 0) {
            $mail->line("**{$this->breachSubjectCount}** breach(es) still require notification to affected individuals.");
        }

        return $mail
            ->action('Open the Privacy Dashboard', url('/privacy/dashboard'))
            ->line('Please action these to stay compliant with the Privacy Act 2020.');
    }

    public function toDatabase(object $notifiable): array
    {
        $parts = [];

        if ($this->overdueCount > 0) {
            $parts[] = "{$this->overdueCount} overdue request".($this->overdueCount === 1 ? '' : 's');
        }
        if ($this->dueSoonCount > 0) {
            $parts[] = "{$this->dueSoonCount} due soon";
        }
        if ($this->breachOpcCount > 0) {
            $parts[] = "{$this->breachOpcCount} breach".($this->breachOpcCount === 1 ? '' : 'es').' awaiting OPC notification';
        }
        if ($this->breachSubjectCount > 0) {
            $parts[] = "{$this->breachSubjectCount} breach".($this->breachSubjectCount === 1 ? '' : 'es').' awaiting subject notification';
        }

        return [
            'title' => 'Privacy deadlines need attention',
            'message' => $parts !== [] ? ucfirst(implode(' · ', $parts)) : 'Privacy items need attention',
            'overdue_count' => $this->overdueCount,
            'due_soon_count' => $this->dueSoonCount,
            'breach_opc_count' => $this->breachOpcCount,
            'breach_subject_count' => $this->breachSubjectCount,
            'overdue_refs' => $this->overdueRefs,
            'type' => 'privacy_deadline_digest',
            'action_url' => '/privacy/dashboard',
        ];
    }
}
