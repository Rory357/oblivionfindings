<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class CertificationExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $staffName,
        public string $certificationName,
        public string $expiryDate,
        public int $daysRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = NotificationTemplate::findByKey('certification_expiring');

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'document_name' => $this->certificationName,
                'expiry_date' => $this->expiryDate,
                'days_remaining' => (string) $this->daysRemaining,
                'recipient' => $this->staffName,
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        // Fallback to hardcoded content
        return (new MailMessage)
            ->subject("Certification Expiring: {$this->staffName} - {$this->certificationName}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("The **{$this->certificationName}** certification for **{$this->staffName}** expires on **{$this->expiryDate}** ({$this->daysRemaining} days remaining).")
            ->line('Please ensure the staff member arranges renewal before expiry to maintain compliance.')
            ->action('View Certifications', url('/operations/certifications'))
            ->line('Expired certifications may affect rostering eligibility.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Certification Expiring',
            'message' => "{$this->certificationName} for {$this->staffName} expires on {$this->expiryDate} ({$this->daysRemaining} days remaining).",
            'staff_name' => $this->staffName,
            'certification_name' => $this->certificationName,
            'expiry_date' => $this->expiryDate,
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
