<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;
use App\Models\ItTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ItAutomationOperationsPresenter
{
    public function operations(User $viewer, array $period = [], int $page = 1): array
    {
        $actor = $viewer->fresh();
        $allowed = $actor?->can('viewAny', ItTeam::class) ?? false;
        $available = $allowed && Schema::hasColumns('it_automation_runs', ['automation_key', 'status', 'started_at', 'finished_at', 'runtime_ms', 'result_summary']);
        $search = trim((string) ($period['q'] ?? ''));
        $base = ['viewer_user_id' => (int) $viewer->id, 'can_view' => $allowed, 'available' => $available,
            'checked_at' => now()->toIso8601String(), 'total' => null, 'unfinished' => null, 'oldest_unfinished_at' => null,
            'search_query' => $search, 'page' => 1, 'last_page' => 1, 'links' => [], 'rows' => []];
        if (! $available) {
            return $base;
        }
        $query = ItAutomationRun::query()
            ->when($period['automation_from'] ?? null, fn ($query, $from) => $query->whereDate('started_at', '>=', $from))
            ->when($period['automation_to'] ?? null, fn ($query, $to) => $query->whereDate('started_at', '<=', $to));
        if ($search !== '') {
            $this->applySearch($query, $search);
        }
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 25));
        $page = min($lastPage, max(1, $page));
        $unfinished = (clone $query)->where('status', 'running')->whereNull('finished_at');
        $url = fn (int $number) => '/it/setup?'.http_build_query([
            'tab' => 'operations', ...array_intersect_key($period, array_flip(['automation_from', 'automation_to'])),
            ...($search !== '' ? ['q' => $search] : []), 'automation_page' => $number,
        ]);
        $links = [['label' => 'Previous', 'url' => $page > 1 ? $url($page - 1) : null, 'active' => false]];
        for ($number = max(1, min($page - 1, $lastPage - 2)); $number <= min($lastPage, max(3, $page + 1)); $number++) {
            $links[] = ['label' => (string) $number, 'url' => $url($number), 'active' => $page === $number];
        }
        $links[] = ['label' => 'Next', 'url' => $page < $lastPage ? $url($page + 1) : null, 'active' => false];

        return [...$base, 'total' => $total, 'unfinished' => (clone $unfinished)->count(),
            'oldest_unfinished_at' => (clone $unfinished)->oldest('started_at')->first()?->started_at?->toIso8601String(),
            'page' => $page, 'last_page' => $lastPage, 'links' => $links,
            'rows' => (clone $query)->latest('id')->offset(($page - 1) * 25)->limit(25)->get()
                ->map(fn (ItAutomationRun $run) => $this->row($actor, $run))->all()];
    }

    private function applySearch(Builder $query, string $search): void
    {
        $needle = Str::lower($search);
        $labels = app(ItAutomationScheduleCatalog::class)->labels();
        $keys = array_keys(array_filter($labels, fn ($label, $key) => str_contains(Str::lower($label.' '.$key), $needle), ARRAY_FILTER_USE_BOTH));
        $failures = array_values(array_filter(array_keys($labels), fn ($key) => str_contains(Str::lower(ItAutomationRunDiagnostics::failure($key)), $needle)));
        $outcomes = [
            'succeeded' => 'Completed', 'failed' => 'Failed execution failed execution_failed',
            'running' => 'No completed outcome running unfinished', 'pending' => 'Mailbox scan pending',
            'skipped' => 'Work skipped', 'no_work' => 'No eligible work',
            'unknown' => 'Outcome unverified unverified evidence unverified_evidence',
        ];
        $states = array_keys(array_filter($outcomes, fn ($label) => str_contains(Str::lower($label), $needle)));
        $runId = preg_match('/^(?:run\s*)?([0-9]+)$/i', $search, $match) ? $match[1] : null;
        $unknownLabel = str_contains(Str::lower('Unrecognised automation'), $needle);

        // Every predicate below targets public labels, a run ID, or the typed
        // outcome. Hidden provider text cannot be inferred through result counts.
        $query->where(function (Builder $matches) use ($keys, $failures, $states, $runId, $unknownLabel, $labels, $needle): void {
            $matches->whereRaw('1 = 0');
            if ($keys !== []) {
                $matches->orWhereIn('automation_key', $keys);
            }
            if ($states !== []) {
                $matches->orWhere(fn (Builder $outcome) => ItAutomationRunOutcome::whereState($outcome, $states));
            }
            if ($failures !== []) {
                $matches->orWhere(fn (Builder $failure) => ItAutomationRunOutcome::whereState($failure->whereIn('automation_key', $failures), ['failed']));
            }
            if ($runId !== null) {
                $matches->orWhere('id', $runId);
            }
            if ($unknownLabel) {
                $matches->orWhereNotIn('automation_key', array_keys($labels));
            }
            if ($needle === 'run') {
                $matches->orWhereRaw('1 = 1');
            }
        });
    }

    private function row(User $actor, ItAutomationRun $run): array
    {
        $outcome = ItAutomationRunOutcome::state($run);
        $mailboxAllowed = $actor->canDo('integrations.manage_secrets');
        $recovery = match ($run->automation_key) {
            'it.poll-mailbox' => 'Review connection status, pending work and the recorded retry delay. A bounded batch can finish while the mailbox scan still needs another run. Reconfiguration or another active lease may cause work to be skipped.',
            'it.dispatch-notifications' => 'Review delivery outcomes before recovery. Accepted or uncertain messages must not be blindly resent. The existing notification recovery worker handles eligible pending deliveries.',
            'it.retry-attachment-cleanup' => 'Use the attachment cleanup evidence and recovery runbook. Only an authorized operator may run bounded cleanup; unknown or quarantined files are not deletion candidates.',
            'it.check-sla', 'it.close-resolved', 'it.check-approval-deadlines' => 'Review the affected work in the service desk. An authorized operator can investigate this run and use the existing scheduled command for recovery. Each execution is a separate run; an unfinished row does not prove that a worker is still active.',
            default => 'This automation is not in the current schedule catalogue. An authorized operator must identify its owning workflow before recovery.',
        };
        $url = match ($run->automation_key) {
            'it.poll-mailbox' => $mailboxAllowed ? route('settings.it-mailbox', absolute: false) : null,
            'it.dispatch-notifications' => '/it/setup?tab=operations#deliveries',
            'it.check-sla', 'it.close-resolved', 'it.check-approval-deadlines' => '/it',
            default => null,
        };

        return ['id' => (int) $run->id, 'label' => app(ItAutomationScheduleCatalog::class)->labelFor($run->automation_key),
            'outcome' => $outcome, 'execution_status' => in_array($run->status, ItAutomationRun::STATUSES, true) ? $run->status : 'unknown',
            'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(), 'runtime_ms' => $run->runtime_ms,
            'failure_category' => $outcome === 'failed' ? 'execution_failed' : ($outcome === 'unknown' ? 'unverified_evidence' : null),
            'error_summary' => $outcome === 'failed' ? ItAutomationRunDiagnostics::failure($run->automation_key) : null,
            'mailbox_counts' => $mailboxAllowed ? ItAutomationRunOutcome::mailboxCounts($run) : null,
            'recovery_guidance' => $recovery, 'recovery_url' => $url,
            'recovery_label' => $url === null ? null : ($run->automation_key === 'it.poll-mailbox' ? 'Review mailbox recovery' : ($run->automation_key === 'it.dispatch-notifications' ? 'Review deliveries' : 'Review service desk'))];
    }
}
