<?php

namespace App\Services\Client;

use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Positive Behaviour Support insights for the Behaviour / ABC tab. Aggregates
 * structured behaviour_abc_entries (function breakdown, intensity mix, settings,
 * behaviour tags, a frequency curve) plus concern-flagged ClientNote rows as a
 * secondary signal. Read-only — feeds the `behaviour_patterns` prop.
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

        // Guard the window between deploy and migration — this runs on every
        // client-profile load, so a missing table must not 500 the page.
        if (! Schema::hasTable('behaviour_abc_entries')) {
            return $this->emptyPayload($days);
        }

        $since = Carbon::now()->subDays($days);

        $entries = BehaviourAbcEntry::query()
            ->forClient($client->id)
            ->since($since)
            ->recent()
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

        return [
            'window_days' => $days,
            'entry_count' => $entries->count(),
            'concern_note_count' => $concernNotes->count(),
            'escalated_count' => $entries->where('escalated', true)->count(),
            'with_harm_count' => $entries->where('harm_occurred', true)->count(),
            'function_breakdown' => $this->functionBreakdown($entries),
            'intensity_mix' => $this->intensityMix($entries),
            'top_settings' => $this->topValues($entries, fn ($e) => $e->setting, 5),
            'top_strategies' => $this->topValues($entries, fn ($e) => $e->strategies_used, 5),
            'top_behaviour_tags' => $this->topBehaviourTags($entries, $concernNotes),
            'daily_series' => $this->buildDailySeries($entries, $concernNotes, $days),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(int $days): array
    {
        return [
            'window_days' => $days,
            'entry_count' => 0,
            'concern_note_count' => 0,
            'escalated_count' => 0,
            'with_harm_count' => 0,
            'function_breakdown' => [],
            'intensity_mix' => ['low' => 0, 'medium' => 0, 'high' => 0],
            'top_settings' => [],
            'top_strategies' => [],
            'top_behaviour_tags' => [],
            'daily_series' => [],
        ];
    }

    /**
     * Count entries per hypothesised function, ordered by the enum so the
     * breakdown is stable. Always emits a row per function (zeroes included)
     * so the chart axis is consistent.
     *
     * @param  Collection<int, BehaviourAbcEntry>  $entries
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function functionBreakdown(Collection $entries): array
    {
        $counts = $entries
            ->filter(fn ($e) => $e->behaviour_function !== null)
            ->countBy(fn ($e) => $e->behaviour_function->value);

        return collect(BehaviourFunction::cases())
            ->map(fn (BehaviourFunction $f) => [
                'key' => $f->value,
                'label' => $f->label(),
                'count' => (int) ($counts[$f->value] ?? 0),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BehaviourAbcEntry>  $entries
     * @return array{low: int, medium: int, high: int}
     */
    private function intensityMix(Collection $entries): array
    {
        return [
            'low' => $entries->where('intensity', 'low')->count(),
            'medium' => $entries->where('intensity', 'medium')->count(),
            'high' => $entries->where('intensity', 'high')->count(),
        ];
    }

    /**
     * Recurring behaviour tags, merged from ABC entries and concern-flagged
     * notes (both are behaviour signals).
     *
     * @param  Collection<int, BehaviourAbcEntry>  $entries
     * @param  Collection<int, ClientNote>  $concernNotes
     * @return array<int, array<string, mixed>>
     */
    private function topBehaviourTags(Collection $entries, Collection $concernNotes): array
    {
        return $entries
            ->flatMap(fn ($e) => $e->behaviour_tags ?? [])
            ->merge($concernNotes->flatMap(fn ($note) => $note->behaviour_tags ?? []))
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->map(fn ($count, $tag) => ['label' => (string) $tag, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BehaviourAbcEntry>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function buildDailySeries(Collection $entries, Collection $concernNotes, int $days): array
    {
        $series = [];
        $today = Carbon::today();
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = $today->copy()->subDays($i)->toDateString();
            $series[$key] = ['date' => $key, 'entries' => 0, 'concerns' => 0];
        }

        foreach ($entries as $entry) {
            $date = $entry->occurred_at?->toDateString();
            if ($date && isset($series[$date])) {
                $series[$date]['entries']++;
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
     * Best-effort frequency clustering of a short free-text field (setting,
     * strategy). Clusters identical entries — useful for short, repeated values.
     *
     * @param  Collection<int, BehaviourAbcEntry>  $entries
     * @param  callable(BehaviourAbcEntry): ?string  $accessor
     * @return array<int, array<string, mixed>>
     */
    private function topValues(Collection $entries, callable $accessor, int $limit = 5): array
    {
        return $entries
            ->map($accessor)
            ->map(fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])
            ->values()
            ->all();
    }
}
