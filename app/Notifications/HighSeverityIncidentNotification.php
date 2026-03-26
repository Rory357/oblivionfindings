<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class HighSeverityIncidentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $incidentTitle,
        public string $severity,
        public string $siteName,
        public string $reportedBy,
        public ?int $incidentId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = NotificationTemplate::findByKey('incident_alert');

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $severityLabel = strtoupper($this->severity);
            $context = [
                'incident_type' => $this->incidentTitle,
                'severity' => $severityLabel,
                'reporter' => $this->reportedBy,
                'location' => $this->siteName,
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        // Fallback to hardcoded content
        $severityLabel = strtoupper($this->severity);

        return (new MailMessage)
            ->subject("[{$severityLabel}] Incident: {$this->incidentTitle}")
            ->greeting('URGENT: ' . ($notifiable->name ?? 'Attention Required'))
            ->line("A **{$severityLabel}** severity incident has been reported at **{$this->siteName}**.")
            ->line("**Incident:** {$this->incidentTitle}")
            ->line("**Reported by:** {$this->reportedBy}")
            ->line('Immediate review and action is required as per the escalation protocol.')
            ->action('View Incident', url('/operations/incidents' . ($this->incidentId ? "/{$this->incidentId}" : '')))
            ->line('This notification was sent to all members of the escalation chain.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "[{$this->severity}] Incident Reported",
            'message' => "{$this->incidentTitle} at {$this->siteName} - reported by {$this->reportedBy}. Immediate action required.",
            'incident_title' => $this->incidentTitle,
            'severity' => $this->severity,
            'site_name' => $this->siteName,
            'reported_by' => $this->reportedBy,
            'incident_id' => $this->incidentId,
        ];
    }
}
