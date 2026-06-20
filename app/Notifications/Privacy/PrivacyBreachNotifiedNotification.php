<?php

namespace App\Notifications\Privacy;

use App\Models\DataBreachLog;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the privacy team when a notifiable breach is reported to the OPC or to
 * affected individuals — turns the lifecycle action into a real, durable
 * notification (bell + email) rather than a silent timestamp.
 */
class PrivacyBreachNotifiedNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $channel  'opc' | 'subjects'
     */
    public function __construct(
        private DataBreachLog $breach,
        private string $channel,
        private ?string $detail = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function isOpc(): bool
    {
        return $this->channel === 'opc';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = $this->breach->breach_reference ?? 'breach';
        $who = $this->isOpc() ? 'the Office of the Privacy Commissioner' : 'the affected individuals';

        $mail = (new MailMessage)
            ->subject(($this->isOpc() ? 'OPC notified' : 'Affected individuals notified')." — {$ref}")
            ->line("A notifiable privacy breach has been reported to {$who}.")
            ->line("Breach: **{$ref}**");

        if ($this->detail !== null && $this->detail !== '') {
            $mail->line(($this->isOpc() ? 'OPC reference: ' : 'Method: ').$this->detail);
        }

        return $mail->action('View breach', url('/privacy/dashboard?breach='.$this->breach->id));
    }

    public function toDatabase(object $notifiable): array
    {
        $ref = $this->breach->breach_reference ?? 'breach';

        return [
            'title' => $this->isOpc() ? 'OPC notified of breach' : 'Individuals notified of breach',
            'message' => ($this->isOpc()
                ? "Breach {$ref} reported to the Privacy Commissioner"
                : "Affected individuals notified for breach {$ref}")
                .($this->detail !== null && $this->detail !== '' ? " · {$this->detail}" : ''),
            'breach_id' => $this->breach->id,
            'channel' => $this->channel,
            'type' => 'privacy_breach_'.($this->isOpc() ? 'opc_notified' : 'subjects_notified'),
            'action_url' => '/privacy/dashboard?breach='.$this->breach->id,
        ];
    }
}
