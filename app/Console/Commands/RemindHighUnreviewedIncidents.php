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
        if ($hours < 1) {
            $hours = 1;
        }

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
            if (! $client) {
                continue;
            }

            app(NotificationService::class)->notifyCrud(
                null,
                'high_unreviewed_reminder',
                'incidents',
                $incident,
                $client,
                [
                    'event_key' => 'incidents.high_unreviewed_reminder',
                    'title' => 'High severity incident awaiting review',
                    'body' => 'A high severity incident has been submitted and is still awaiting review'
                        .($incident->created_at ? ' ('.self::waitingFor($incident->created_at).' so far).' : '.'),
                    'url' => url("/incidents/{$incident->id}"),
                    // Hourly check: while a person's reminder for this incident
                    // is unread, it is refreshed (and moved to the top) rather
                    // than sent again; once read, the next run sends a new one.
                    'refresh_unread_copy' => true,
                    'context' => [
                        'Client' => trim($client->first_name.' '.$client->last_name),
                        'Incident' => 'ClientIncident #'.$incident->id,
                        'Severity' => (string) ($incident->severity ?? ''),
                        'Submitted' => $incident->submitted_at?->format('Y-m-d H:i'),
                        'Waiting since' => $incident->created_at?->format('Y-m-d H:i'),
                        'Waiting' => $incident->created_at ? self::waitingFor($incident->created_at) : null,
                    ],
                ]
            );
        }

        $this->info('High unreviewed incident reminders sent: '.$incidents->count());

        return self::SUCCESS;
    }

    /** "5 hours" or "3 days": how long the incident has waited. */
    public static function waitingFor(\DateTimeInterface $since): string
    {
        $hours = max(1, (int) floor(abs(now()->diffInHours($since))));

        return $hours < 48
            ? $hours.' '.($hours === 1 ? 'hour' : 'hours')
            : intdiv($hours, 24).' days';
    }
}
