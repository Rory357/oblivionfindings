<?php

namespace App\Notifications;

use App\Domain\SecurityDevices\Reports\MonthlyReport;
use App\Domain\SecurityDevices\Reports\MonthlyReportPdf;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The monthly Security & devices report by email (pg-sdr-18b), sent from the queue on the 1st.
 * Built when it's sent, as the person it goes to: only their own Sites' figures, and only while
 * they still may see reports. The camera viewing log never goes by email; it stays in Oblivion.
 */
class SecurityDevicesMonthlyReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $month) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ! empty($notifiable->email) ? ['mail'] : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && $notifiable->isApproved() && MonthlyReport::canView($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $now = CarbonImmutable::now('UTC');
        $report = app(MonthlyReport::class);
        $month = $report->month($this->month, $now);
        $figures = $report->figures($notifiable, null, $month, $now);

        $mail = (new MailMessage)
            ->subject('Security & devices report: '.$month['label'])
            ->greeting('Kia ora '.($notifiable->name ?: 'team').',')
            ->line('Here is the '.$month['label'].' report for the Sites you can see in Oblivion. The PDF has the details.');
        foreach ($this->lines($figures, $month['short']) as $line) {
            $mail->line($line);
        }
        $pdf = app(MonthlyReportPdf::class)->output($month, $figures, null, $notifiable, 'Sent to', $now);

        return $mail
            ->action('Open the report', url('/security-devices?tab=reports&month='.$month['key']))
            ->line('Who watched cameras is in the report in Oblivion, for people who may see it. It isn’t emailed.')
            ->attachData($pdf, 'security-devices-report-'.$month['key'].'.pdf', ['mime' => 'application/pdf']);
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return list<string>
     */
    private function lines(array $figures, string $short): array
    {
        $offline = $figures['offline'] === null
            ? 'Time offline: not available (no router or gateway is watched).'
            : 'Time offline: '.implode(', ', array_map(
                fn (array $site): string => $site['name'].' '.MonthlyReportPdf::minutes((int) $site['minutes']),
                array_reverse($figures['offline']['sites']),
            )).'.';
        $kinds = array_slice($figures['alerts']['kinds'], 0, 3);
        $alerts = $figures['alerts']['total'] === 0
            ? "Alerts: none in {$short}."
            : 'Alerts: '.$figures['alerts']['total'].' ('.implode(', ', array_map(fn (array $kind): string => mb_strtolower($kind['label']).' '.$kind['count'], $kinds)).(count($figures['alerts']['kinds']) > 3 ? ' and more' : '').').';
        $safety = $figures['safety'];
        $safetyLine = $safety === null || $safety['month']['count'] === 0
            ? "Safety alerts: none in {$short}."
            : 'Safety alerts: '.$safety['month']['count'].', picked up in '.($safety['month']['average_minutes'] !== null ? MonthlyReportPdf::wait((float) $safety['month']['average_minutes']) : 'no time recorded').' on average'
                .($safety['month']['not_picked'] > 0 ? '; '.$safety['month']['not_picked'].' not picked up' : '').'.';
        $upkeep = $figures['upkeep'] === null
            ? 'Upkeep: not available.'
            : 'Upkeep: '.$figures['upkeep']['batteries'].' batteries changed, '.$figures['upkeep']['software'].' software updates, '.$figures['upkeep']['settings'].' settings changes.';
        $cameras = $figures['cameras'];
        $camerasLine = $cameras === null || $cameras['percent'] === null
            ? 'Camera picture time: not available.'
            : 'Camera picture time: '.$cameras['percent'].'% with a picture across '.$cameras['cameras'].' '.($cameras['cameras'] === 1 ? 'camera' : 'cameras').'.';

        return [$offline, $alerts, $safetyLine, $upkeep, $camerasLine];
    }
}
