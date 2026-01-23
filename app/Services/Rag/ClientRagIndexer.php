<?php

namespace App\Services\Rag;

use App\Models\Client;
use App\Models\TimelineEvent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientRagIndexer
{
    public function buildMarkdown(Client $client, int $days = 90): string
    {
        $client->loadMissing(['site:id,name', 'medicalProfile', 'medications']);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(1200)
            ->with(['actor:id,name', 'site:id,name'])
            ->get();

        $lines = [];
        $lines[] = "# Client Knowledge: " . trim($client->first_name . ' ' . $client->last_name);
        $lines[] = "Client ID: {$client->id}";
        $lines[] = $client->site ? "Site: {$client->site->name}" : "";
        $lines[] = "Generated at: " . now()->toDateTimeString();
        $lines[] = "";

        $mp = $client->medicalProfile;
        $lines[] = "## Medical Profile";
        $lines[] = "- Medical history: " . ($mp?->medical_history ? Str::squish($mp->medical_history) : 'N/A');
        $lines[] = "- Disabilities: " . ($mp?->disabilities ? Str::squish($mp->disabilities) : 'N/A');
        $lines[] = "- Allergies: " . ($mp?->allergies ? Str::squish($mp->allergies) : 'N/A');
        $lines[] = "- Notes: " . ($mp?->notes ? Str::squish($mp->notes) : 'N/A');
        $lines[] = "";

        $lines[] = "## Medications";
        if ($client->medications->count() === 0) {
            $lines[] = "- None recorded";
        } else {
            foreach ($client->medications as $m) {
                $parts = array_filter([
                    "name={$m->name}",
                    $m->dosage ? "dosage={$m->dosage}" : null,
                    $m->frequency ? "frequency={$m->frequency}" : null,
                    $m->route ? "route={$m->route}" : null,
                    $m->prescriber ? "prescriber={$m->prescriber}" : null,
                    $m->start_date ? "start={$m->start_date->toDateString()}" : null,
                    $m->end_date ? "end={$m->end_date->toDateString()}" : null,
                ]);
                $lines[] = "- " . implode(' | ', $parts);
                if ($m->instructions) {
                    $lines[] = "  - instructions: " . Str::squish($m->instructions);
                }
            }
        }
        $lines[] = "";

        $lines[] = "## Timeline events (last {$days} days)";
        foreach ($events as $e) {
            $when = optional($e->occurred_at)->toDateTimeString();
            $actor = $e->actor ? $e->actor->name : 'System';
            $site = $e->site ? $e->site->name : ($client->site?->name ?? '');
            $subject = Str::squish((string) $e->subject);
            $body = is_string($e->body) ? Str::squish(Str::limit($e->body, 700)) : '';

            $lines[] = "- [event_id={$e->id}] {$when} | type={$e->type} | actor={$actor}" . ($site ? " | site={$site}" : '') . " | subject={$subject}";
            if ($body !== '') {
                $lines[] = "  - body: {$body}";
            }
        }

        return implode("\n", array_filter($lines, fn($l) => $l !== null));
    }

    public function writeToStorage(Client $client, string $markdown): string
    {
        $path = "rag/client_{$client->id}_latest.md";
        Storage::disk('local')->put($path, $markdown);
        return Storage::disk('local')->path($path);
    }
}
