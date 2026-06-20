<?php

namespace App\Notifications\Clinical;

use App\Console\Commands\ClinicalDeteriorationReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Health & Clinical — daily deterioration digest delivered to clinical oversight
 * staff (in-app, via the database channel / notification bell). Sent by
 * {@see ClinicalDeteriorationReminders}. Database-only so the scheduled run never
 * depends on mail configuration; add 'mail' to via() once SMTP is configured.
 */
class ClinicalWatchDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $clientsOnWatch,
        public int $overdueObservations,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $parts = [];
        if ($this->clientsOnWatch > 0) {
            $parts[] = "{$this->clientsOnWatch} client".($this->clientsOnWatch === 1 ? '' : 's').' on the deterioration watch';
        }
        if ($this->overdueObservations > 0) {
            $parts[] = "{$this->overdueObservations} overdue observation".($this->overdueObservations === 1 ? '' : 's');
        }

        return [
            'kind' => 'clinical_watch_digest',
            'title' => 'Clinical deterioration watch needs attention',
            'body' => 'You have '.implode(' and ', $parts).' to review.',
            'clients_on_watch' => $this->clientsOnWatch,
            'overdue_observations' => $this->overdueObservations,
            'url' => '/health-clinical',
        ];
    }
}
