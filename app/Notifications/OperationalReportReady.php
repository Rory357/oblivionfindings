<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OperationalReportReady extends Notification
{
    public function __construct(private readonly string $domain) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'operational_report_ready', 'title' => 'Your scheduled report is ready',
            'body' => 'Open Recent and scheduled runs. Access is checked again before viewing or downloading.',
            'url' => match ($this->domain) {
                'fleet' => '/fleet-assets/reports/builder','self' => '/my-day/safety-reports',default => '/operations/people-location-reports/'.$this->domain
            }];
    }
}
