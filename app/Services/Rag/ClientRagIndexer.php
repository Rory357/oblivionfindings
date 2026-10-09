<?php

namespace App\Services\Rag;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Llm\AiMedicationDataPolicy;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientRagIndexer
{
    public function __construct(private readonly AiMedicationDataPolicy $medicationData) {}

    /**
     * The knowledge snapshot uploaded for one question. It is built for the
     * asker ($reader): medication data follows AiMedicationDataPolicy (none
     * by default, decision D5) and a portal asker gets only portal-visible
     * timeline events their portal access allows.
     */
    public function buildMarkdown(Client $client, int $days = 90, ?User $reader = null): string
    {
        $client->loadMissing(['site:id,name', 'medicalProfile']);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(1200)
            ->with(['actor:id,name', 'site:id,name']);
        $this->medicationData->scopeTimeline($events, $reader);
        if ($reader?->canAccessClientPortal($client)) {
            $portal = app(PortalClientSectionAccess::class);
            $events->where('visibility', 'portal');
            $portal->constrainTimeline($events, $portal->for($reader, $client));
        }
        $events = $events->get();

        $lines = [];
        $lines[] = '# Client Knowledge: '.trim($client->first_name.' '.$client->last_name);
        $lines[] = "Client ID: {$client->id}";
        $lines[] = $client->site ? "Site: {$client->site->name}" : '';
        $lines[] = 'Generated at: '.now()->toDateTimeString();
        $lines[] = '';

        // The profile's Medical section has its own gate (viewMedications).
        if ($reader !== null && Gate::forUser($reader)->allows('viewMedications', $client)) {
            $mp = $client->medicalProfile;
            $lines[] = '## Medical Profile';
            $lines[] = '- Medical history: '.$this->text($mp?->medical_history);
            $lines[] = '- Disabilities: '.$this->list($mp?->disabilities, ClientMedicalProfile::DISABILITY_OPTIONS);
            $allergies = $this->medicationData->allergyLabels($reader, $client);
            if ($allergies !== []) {
                $lines[] = '- Allergies: '.implode(', ', $allergies);
            }
            $lines[] = '- Notes: '.$this->text($mp?->notes);
            $lines[] = '';
        }

        $medicines = $this->medicationData->medicines($reader, $client);
        if ($medicines->isNotEmpty()) {
            $lines[] = '## Current medicines (controlled medicines are never included)';
            foreach ($medicines as $m) {
                $parts = array_filter([
                    "name={$m->name}",
                    $m->dosage ? "dosage={$m->dosage}" : null,
                    $m->frequency ? "frequency={$m->frequency}" : null,
                    $m->route ? "route={$m->route}" : null,
                ]);
                $lines[] = '- '.implode(' | ', $parts);
                if ($m->instructions) {
                    $lines[] = '  - instructions: '.Str::squish($m->instructions);
                }
            }
            $lines[] = '';
        }

        $lines[] = "## Timeline events (last {$days} days)";
        foreach ($events as $e) {
            $when = optional($e->occurred_at)->toDateTimeString();
            $actor = $e->actor ? $e->actor->name : 'System';
            $site = $e->site ? $e->site->name : ($client->site?->name ?? '');
            $subject = Str::squish((string) $e->subject);
            $body = is_string($e->body) ? Str::squish(Str::limit($e->body, 700)) : '';

            $lines[] = "- [event_id={$e->id}] {$when} | type={$e->type} | actor={$actor}".($site ? " | site={$site}" : '')." | subject={$subject}";
            if ($body !== '') {
                $lines[] = "  - body: {$body}";
            }
        }

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    public function writeToStorage(Client $client, string $markdown): string
    {
        $path = "rag/client_{$client->id}_latest.md";
        Storage::disk('local')->put($path, $markdown);

        return Storage::disk('local')->path($path);
    }

    private function text(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? Str::squish($value) : 'N/A';
    }

    /**
     * Array-cast profile lists (disabilities) are joined, never squished as
     * strings (that threw a TypeError for any recorded value).
     *
     * @param  array<int, array{value?: string, label?: string}>  $options
     */
    private function list(mixed $values, array $options): string
    {
        $values = is_string($values) ? [$values] : (is_array($values) ? $values : []);
        $labels = collect($options)->pluck('label', 'value');
        $items = collect($values)
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => $value.(isset($labels[$value]) && $labels[$value] !== $value ? ' ('.$labels[$value].')' : ''))
            ->values()
            ->all();

        return $items === [] ? 'N/A' : Str::squish(implode(', ', $items));
    }
}
