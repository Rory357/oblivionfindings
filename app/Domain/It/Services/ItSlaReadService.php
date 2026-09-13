<?php

namespace App\Domain\It\Services;

use App\Models\ItTicket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Read projections share one instant and clock evaluator; callers supply authorized queries. */
final class ItSlaReadService
{
    private const COLUMNS = [
        'id', 'status', 'created_at', 'first_response_due_at', 'resolution_due_at',
        'first_responded_at', 'resolved_at', 'waiting_since', 'sla_paused_minutes',
        'reopened_count', 'sla_policy_snapshot', 'first_response_breached_at', 'resolution_breached_at',
    ];

    public function __construct(private readonly ItSlaClockService $clocks) {}

    public function present(ItTicket $ticket): array
    {
        $verdict = $this->clocks->verdict($ticket, $this->evaluatedAt());

        return ['sla_state' => $verdict['state'], 'sla' => $verdict];
    }

    public function evaluatedAt(): CarbonImmutable
    {
        // Controllers may survive more than one HTTP request (including in
        // long-running workers). Store the instant on this request, not on a
        // retained controller/service instance, so freshness advances correctly.
        $attributes = request()->attributes;
        $at = $attributes->get('it.sla_evaluated_at');
        if (! $at instanceof CarbonImmutable) {
            $at = CarbonImmutable::now();
            $attributes->set('it.sla_evaluated_at', $at);
        }

        return $at;
    }

    public function summarize(Builder $authorizedQuery): array
    {
        return $this->clocks->summarize($this->records($authorizedQuery), $this->evaluatedAt());
    }

    /**
     * Filter before pagination using live business-calendar evidence, never the
     * watchdog's cached state. Only matching integer IDs are retained; models
     * are streamed without eager-loaded private content or a hidden row cap.
     */
    public function whereState(Builder $authorizedQuery, array $states): Builder
    {
        $ids = [];
        $at = $this->evaluatedAt();
        foreach ($this->records($authorizedQuery) as $ticket) {
            if (in_array($this->clocks->verdict($ticket, $at)['state'], $states, true)) {
                $ids[] = (int) $ticket->id;
            }
        }

        return $authorizedQuery->whereIntegerInRaw('it_tickets.id', $ids);
    }

    /** Retain only the requested number of attention rows while scanning the scoped queue. */
    public function attentionIds(Builder $authorizedQuery, int $limit = 6): array
    {
        $rows = [];
        $at = $this->evaluatedAt();
        foreach ($this->records($authorizedQuery) as $ticket) {
            $verdict = $this->clocks->verdict($ticket, $at);
            if (! in_array($verdict['state'], ['breached', 'at_risk'], true)) {
                continue;
            }
            $deadlines = array_filter(array_map(
                fn (array $clock) => in_array($clock['state'], ['breached', 'at_risk'], true) ? $clock['due_at'] : null,
                $verdict['clocks'],
            ));
            $rows[] = [
                'id' => (int) $ticket->id,
                'rank' => [$verdict['state'] === 'breached' ? 0 : 1, $deadlines ? min($deadlines) : '', (int) $ticket->id],
            ];
            usort($rows, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);
            if (count($rows) > $limit) {
                array_pop($rows);
            }
        }

        return array_column($rows, 'id');
    }

    private function records(Builder $authorizedQuery): iterable
    {
        return (clone $authorizedQuery)->withoutEagerLoads()->reorder()
            ->select(array_map(fn (string $column) => 'it_tickets.'.$column, self::COLUMNS))
            ->lazyById(250, 'it_tickets.id', 'id');
    }
}
