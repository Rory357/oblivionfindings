<?php

namespace App\Console\Commands;

use App\Models\IncidentFollowup;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class RemindOverdueFollowups extends Command
{
    protected $signature = 'followups:remind-overdue';
    protected $description = 'Send in-app reminders for overdue incident follow-ups.';

    public function handle(): int
    {
        $cutoff = now();

        $followups = IncidentFollowup::query()
            ->whereNull('completed_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', $cutoff)
            ->with([
                'incident:id,client_id,severity,status',
                'incident.client:id,first_name,last_name',
                'assignedTo:id,name',
            ])
            ->limit(250)
            ->get();

        foreach ($followups as $f) {
            $incident = $f->incident;
            $client = $incident?->client;
            if (!$incident || !$client) {
                continue;
            }

            $assignedId = $f->assigned_to_user_id ? [(int) $f->assigned_to_user_id] : [];

            app(NotificationService::class)->notifyCrud(
                null,
                'overdue_reminder',
                'followups',
                $f,
                $client,
                [
                    'event_key' => 'followups.overdue_reminder',
                    'title' => 'Follow-up overdue',
                    'body' => 'An incident follow-up is overdue and needs attention.',
                    'url' => url("/incidents/{$incident->id}"),
                    'target_user_ids' => $assignedId,
                    'context' => [
                        'Client' => trim($client->first_name . ' ' . $client->last_name),
                        'Incident' => 'ClientIncident #' . $incident->id,
                        'Severity' => (string) ($incident->severity ?? ''),
                        'Due' => $f->due_at?->format('Y-m-d H:i'),
                        'Assigned to' => $f->assignedTo?->name,
                    ],
                ]
            );
        }

        $this->info('Overdue follow-up reminders sent: ' . $followups->count());

        return self::SUCCESS;
    }
}
