<?php

namespace App\Console\Commands;

use App\Models\ClientIncident;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class RemindHighUnreviewedIncidents extends Command
{
    protected $signature = 'incidents:remind-high-unreviewed {--hours=4}';
    protected $description = 'Send in-app reminders for high severity incidents awaiting review.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        if ($hours < 1) $hours = 1;

        $cutoff = now()->subHours($hours);

        $incidents = ClientIncident::query()
            ->where('status', 'submitted')
            ->whereNull('reviewed_at')
            ->where('severity', 'high')
            ->where('created_at', '<', $cutoff)
            ->with(['client:id,first_name,last_name'])
            ->limit(200)
            ->get();

        foreach ($incidents as $incident) {
            $client = $incident->client;
            if (!$client) continue;

            app(NotificationService::class)->notifyCrud(
                null,
                'high_unreviewed_reminder',
                'incidents',
                $incident,
                $client,
                [
                    'event_key' => 'incidents.high_unreviewed_reminder',
                    'title' => 'High severity incident awaiting review',
                    'body' => 'A high severity incident has been submitted and is still awaiting review.',
                    'url' => url("/incidents/{$incident->id}"),
                    'context' => [
                        'Client' => trim($client->first_name . ' ' . $client->last_name),
                        'Incident' => 'ClientIncident #' . $incident->id,
                        'Severity' => (string) ($incident->severity ?? ''),
                        'Submitted' => $incident->submitted_at?->format('Y-m-d H:i'),
                        'Waiting since' => $incident->created_at?->format('Y-m-d H:i'),
                    ],
                ]
            );
        }

        $this->info('High unreviewed incident reminders sent: ' . $incidents->count());
        return self::SUCCESS;
    }
}
