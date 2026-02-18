<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        return (new MailMessage)
            ->subject('HR scheduled report ready')
            ->line('A scheduled HR report export has been generated.')
            ->line('Report: ' . str_replace('_', ' ', $this->export->report_type))
            ->line('Generated at: ' . optional($this->export->generated_at)->toDateTimeString())
            ->action('Open HR Reports', url('/hr/reports'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_scheduled_report_ready',
            'report_export_id' => $this->export->id,
            'report_type' => $this->export->report_type,
            'generated_at' => optional($this->export->generated_at)->toIso8601String(),
            'download_url' => url("/hr/reports/exports/{$this->export->id}/download"),
            'action_url' => '/hr/reports',
        ];
    }
}

