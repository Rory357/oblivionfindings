<?php

namespace App\Services\Client;

use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use App\Support\WorkerClock;
use Illuminate\Support\Str;

/**
 * Write path for structured Antecedent → Behaviour → Consequence (ABC) records.
 * Stores worker-local times as UTC (per the eloquent-timezone rule) and emits a
 * `behaviour_abc_entry` Timeline event so entries also appear in the unified
 * activity stream. Read/aggregation lives in the controller + BehaviourPatternsService.
 */
class BehaviourAbcService
{
    public const TIMELINE_TYPE = 'behaviour_abc_entry';

    public function __construct(
        protected TimelineEmitter $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function record(Client $client, User $user, array $input, ?Shift $shift = null): BehaviourAbcEntry
    {
        $entry = BehaviourAbcEntry::create([
            'client_id' => $client->id,
            'site_id' => $shift?->site_id ?? $client->site_id,
            'shift_id' => $shift?->id,
            'occurred_at' => WorkerClock::toUtc($input['occurred_at'] ?? null) ?? now(),
            'setting' => $input['setting'] ?? null,
            'others_present' => $input['others_present'] ?? null,
            'antecedent' => $input['antecedent'],
            'behaviour' => $input['behaviour'],
            'behaviour_tags' => $this->cleanTags($input['behaviour_tags'] ?? null),
            'consequence' => $input['consequence'],
            'behaviour_function' => $input['behaviour_function'] ?? null,
            'intensity' => $input['intensity'] ?? 'low',
            'duration_seconds' => $input['duration_seconds'] ?? null,
            'strategies_used' => $input['strategies_used'] ?? null,
            'harm_occurred' => (bool) ($input['harm_occurred'] ?? false),
            'harm_notes' => ($input['harm_occurred'] ?? false) ? ($input['harm_notes'] ?? null) : null,
            'escalated' => (bool) ($input['escalated'] ?? false),
            'requires_followup' => (bool) ($input['requires_followup'] ?? false),
            'followup_notes' => ($input['requires_followup'] ?? false) ? ($input['followup_notes'] ?? null) : null,
            'linked_care_plan_id' => $input['linked_care_plan_id'] ?? null,
            'recorded_by' => $user->id,
        ]);

        $this->emitTimeline($entry, $user);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(BehaviourAbcEntry $entry, User $user, array $input): BehaviourAbcEntry
    {
        $changes = [
            'setting' => $input['setting'] ?? null,
            'others_present' => $input['others_present'] ?? null,
            'antecedent' => $input['antecedent'],
            'behaviour' => $input['behaviour'],
            'behaviour_tags' => $this->cleanTags($input['behaviour_tags'] ?? null),
            'consequence' => $input['consequence'],
            'behaviour_function' => $input['behaviour_function'] ?? null,
            'intensity' => $input['intensity'] ?? 'low',
            'duration_seconds' => $input['duration_seconds'] ?? null,
            'strategies_used' => $input['strategies_used'] ?? null,
            'harm_occurred' => (bool) ($input['harm_occurred'] ?? false),
            'harm_notes' => ($input['harm_occurred'] ?? false) ? ($input['harm_notes'] ?? null) : null,
            'escalated' => (bool) ($input['escalated'] ?? false),
            'requires_followup' => (bool) ($input['requires_followup'] ?? false),
            'followup_notes' => ($input['requires_followup'] ?? false) ? ($input['followup_notes'] ?? null) : null,
            'linked_care_plan_id' => $input['linked_care_plan_id'] ?? null,
        ];

        if (! empty($input['occurred_at'])) {
            $changes['occurred_at'] = WorkerClock::toUtc($input['occurred_at']) ?? $entry->occurred_at;
        }

        // Close out a follow-up when the editor marks it done.
        if (! empty($input['followup_completed']) && $entry->followup_completed_at === null) {
            $changes['followup_completed_at'] = now();
            $changes['followup_completed_by'] = $user->id;
            $changes['requires_followup'] = false;
        }

        $entry->update($changes);

        return $entry->refresh();
    }

    /**
     * @param  mixed  $tags
     * @return array<int, string>|null
     */
    private function cleanTags($tags): ?array
    {
        if (! is_array($tags)) {
            return null;
        }

        $clean = collect($tags)
            ->filter(fn ($t) => is_string($t) && trim($t) !== '')
            ->map(fn ($t) => trim($t))
            ->unique()
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }

    private function emitTimeline(BehaviourAbcEntry $entry, User $user): void
    {
        $body = collect([
            $entry->setting ? "Setting: {$entry->setting}" : null,
            'A — ' . Str::limit($entry->antecedent, 120),
            'B — ' . Str::limit($entry->behaviour, 120),
            'C — ' . Str::limit($entry->consequence, 120),
        ])->filter()->implode(' · ');

        $this->timeline->record([
            'type' => self::TIMELINE_TYPE,
            'source_type' => BehaviourAbcEntry::class,
            'source_id' => $entry->id,
            'occurred_at' => $entry->occurred_at,
            'actor_user_id' => $user->id,
            'client_id' => $entry->client_id,
            'shift_id' => $entry->shift_id,
            'site_id' => $entry->site_id,
            'subject' => 'ABC entry logged',
            'body' => $body,
            'meta' => [
                'behaviour_abc_entry_id' => $entry->id,
                'behaviour_function' => $entry->behaviour_function?->value,
                'intensity' => $entry->intensity,
                'escalated' => $entry->escalated,
            ],
            'visibility' => 'internal',
            'created_by' => $user->id,
        ]);
    }
}
