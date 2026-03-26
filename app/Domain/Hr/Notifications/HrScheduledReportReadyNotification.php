<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrReportExport;
use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class HrScheduledReportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected HrReportExport $export,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = NotificationTemplate::findByKey('hr_report_ready');

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'document_name' => ucwords(str_replace('_', ' ', $this->export->report_type)),
                'date' => optional($this->export->generated_at)->format('d/m/Y') ?? now()->format('d/m/Y'),
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        return (new MailMessage)
            ->subject('HR scheduled report ready')
            ->line('A scheduled HR report export has been generated.')
            ->line('Report: ' . str_replace('_', ' ', $this->export->report_type))
            ->line('Generated at: ' . optional($this->export->generated_at)->toDateTimeString())
            ->action('Open HR Reports', url('/hr/reports'));
    }

    public function toArray(object $notifiable): array
    {
        $formattedType = ucwords(str_replace('_', ' ', $this->export->report_type));

        return [
            'type' => 'hr_scheduled_report_ready',
            'title' => "HR Report Ready: {$formattedType}",
            'message' => 'Your scheduled HR report has been generated and is ready for download.',
            'url' => '/hr/reports',
            'action_url' => '/hr/reports',
            'context' => [
                'Report type' => $formattedType,
                'Generated at' => optional($this->export->generated_at)->toDateTimeString() ?? 'N/A',
            ],
            'report_export_id' => $this->export->id,
            'report_type' => $this->export->report_type,
            'generated_at' => optional($this->export->generated_at)->toIso8601String(),
            'download_url' => url("/hr/reports/exports/{$this->export->id}/download"),
        ];
    }
}
