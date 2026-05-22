<?php

namespace App\Services\Client;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Carbon\Carbon;

/**
 * Phase 3 behaviour pattern insights: aggregates clinical_observations and
 * concern-flagged ClientNote rows to surface top triggers, common
 * antecedents, recurring concerns, and a simple frequency-over-time curve.
 * Read-only — feeds the Observations and Actions-and-Reviews tabs.
 */
class BehaviourPatternsService
{
    /**
     * @return array<string, mixed>
     */
    public function forClient(Client $client, ?User $user = null, int $days = 30): array
    {
        if ($user !== null && ! $user->canDo('observations.viewAny')
            && ! $user->canDo('clients.viewAny')
            && ! $user->canDo('clients.viewAssigned')) {
            return $this->emptyPayload($days);
        }

        $since = Carbon::now()->subDays($days);

        $observations = ClinicalObservation::query()
            ->where('client_id', $client->id)
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('recorded_at')
            ->get();

        $concernNotes = ClientNote::query()
            ->where('client_id', $client->id)
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                $q->where('category', 'concern')
                    ->orWhereJsonContains('concerns_flags', 'behaviour')
                    ->orWhere('is_flagged', true);
            })
            ->orderByDesc('created_at')
            ->get();

        $byDay = $this->buildDailySeries($observations, $concernNotes, $days);
        $topTriggers = $this->topValues($observations, 'trigger', 5);
        $topAntecedents = $this->topValues($observations, 'antecedent', 5);
        $topResponses = $this->topValues($observations, 'response', 5);

        $behaviourTags = $concernNotes
            ->flatMap(fn ($note) => $note->behaviour_tags ?? [])
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->map(fn ($count, $tag) => ['label' => (string) $tag, 'count' => $count])
            ->values()
            ->all();

        return [
            'window_days' => $days,
            'observation_count' => $observations->count(),
            'concern_note_count' => $concernNotes->count(),
            'top_triggers' => $topTriggers,
            'top_antecedents' => $topAntecedents,
            'top_responses' => $topResponses,
            'top_behaviour_tags' => $behaviourTags,
            'daily_series' => $byDay,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(int $days): array
    {
        return [
            'window_days' => $days,
            'observation_count' => 0,
            'concern_note_count' => 0,
            'top_triggers' => [],
            'top_antecedents' => [],
            'top_responses' => [],
            'top_behaviour_tags' => [],
            'daily_series' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDailySeries($observations, $concernNotes, int $days): array
    {
        $series = [];
        $today = Carbon::today();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $today->copy()->subDays($i);
            $key = $day->toDateString();
            $series[$key] = [
                'date' => $key,
                'observations' => 0,
                'concerns' => 0,
            ];
        }

        foreach ($observations as $observation) {
            $date = $observation->recorded_at?->toDateString();
            if ($date && isset($series[$date])) {
                $series[$date]['observations']++;
            }
        }
        foreach ($concernNotes as $note) {
            $date = $note->created_at?->toDateString();
            if ($date && isset($series[$date])) {
                $series[$date]['concerns']++;
            }
        }

        return array_values($series);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topValues($observations, string $key, int $limit = 5): array
    {
        return $observations
            ->pluck('data')
            ->map(function ($data) use ($key) {
                if (! is_array($data)) {
                    return null;
                }
                $value = $data[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }

                return null;
            })
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])
            ->values()
            ->all();
    }
}
