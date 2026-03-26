<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ServiceAgreementExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $clientName,
        public string $expiryDate,
        public int $daysRemaining,
        public ?int $agreementId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = NotificationTemplate::findByKey('agreement_expiring');

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'client' => $this->clientName,
                'expiry_date' => $this->expiryDate,
                'days_remaining' => (string) $this->daysRemaining,
                'document_name' => "Service Agreement — {$this->clientName}",
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        // Fallback to hardcoded content
        return (new MailMessage)
            ->subject("Service Agreement Expiring: {$this->clientName} ({$this->daysRemaining} days)")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("The service agreement for **{$this->clientName}** is expiring on **{$this->expiryDate}** ({$this->daysRemaining} days remaining).")
            ->line('Please arrange for a renewal or review before it lapses.')
            ->action('View Service Agreements', url('/operations/service-agreements'))
            ->line('Timely renewal ensures continuity of care.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Service Agreement Expiring',
            'message' => "Service agreement for {$this->clientName} expires on {$this->expiryDate} ({$this->daysRemaining} days remaining).",
            'client_name' => $this->clientName,
            'expiry_date' => $this->expiryDate,
            'days_remaining' => $this->daysRemaining,
            'agreement_id' => $this->agreementId,
        ];
    }
}
