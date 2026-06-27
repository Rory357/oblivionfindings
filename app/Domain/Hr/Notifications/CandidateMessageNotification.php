<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A free-text message a recruiter sends to a candidate (single or bulk) from
 * the pipeline — e.g. a timeline update or a request for information. Delivered
 * on-demand to the candidate's personal email; the body is authored by the
 * recruiter and rendered as plain lines.
 */
class CandidateMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCandidate $candidate,
        private string $subjectLine,
        private string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = trim((string) ($this->candidate->first_name ?? '')) ?: 'there';

        $mail = (new MailMessage)
            ->subject($this->subjectLine)
            ->greeting("Kia ora {$firstName},");

        // Render each non-empty line of the recruiter's message as its own line.
        foreach (preg_split('/\r\n|\r|\n/', $this->body) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $mail->line($line);
            }
        }

        return $mail->salutation('Ngā mihi, The Recruitment Team');
    }
}
