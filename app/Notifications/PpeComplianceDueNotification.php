<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * PPE compliance reminder digest (in-app, database channel / notification bell).
 *
 * Two audiences, sent by {@see \App\Console\Commands\PpeComplianceReminders}:
 *  - 'worker'  → the worker's own PPE that still needs acknowledgement or an RPE
 *                fit-test (complements the My Day "My PPE" card with a push nudge).
 *  - 'manager' → org-level inspections overdue / items expiring / condemned awaiting
 *                disposal, to users holding hazards.manage.
 *
 * Database-only so the scheduled run never depends on mail configuration.
 */
class PpeComplianceDueNotification extends Notification
{
    use Queueable;

    /**
     * @param  'worker'|'manager'  $audience
     * @param  array<string,int>  $counts
     */
    public function __construct(
        public string $audience,
        public array $counts,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $plural = fn (int $n, string $s) => $n.' '.$s.($n === 1 ? '' : 's');

        if ($this->audience === 'worker') {
            $parts = [];
            if (($this->counts['unacknowledged'] ?? 0) > 0) {
                $parts[] = $plural($this->counts['unacknowledged'], 'item').' to acknowledge';
            }
            if (($this->counts['fit_test_due'] ?? 0) > 0) {
                $parts[] = $plural($this->counts['fit_test_due'], 'respirator').' needing a fit-test';
            }

            return [
                'kind' => 'ppe_worker_due',
                'title' => 'Your PPE needs your attention',
                'body' => 'You have '.implode(' and ', $parts).' issued to you.',
                'counts' => $this->counts,
                'url' => '/my-day',
            ];
        }

        $parts = [];
        if (($this->counts['inspections_overdue'] ?? 0) > 0) {
            $parts[] = $plural($this->counts['inspections_overdue'], 'inspection').' overdue';
        }
        if (($this->counts['expiring'] ?? 0) > 0) {
            $parts[] = $plural($this->counts['expiring'], 'item').' expiring';
        }
        if (($this->counts['condemned'] ?? 0) > 0) {
            $parts[] = $plural($this->counts['condemned'], 'item').' awaiting disposal';
        }

        return [
            'kind' => 'ppe_manager_due',
            'title' => 'PPE compliance needs attention',
            'body' => 'The PPE register has '.implode(', ', $parts).'.',
            'counts' => $this->counts,
            'url' => '/health-safety/ppe',
        ];
    }
}
