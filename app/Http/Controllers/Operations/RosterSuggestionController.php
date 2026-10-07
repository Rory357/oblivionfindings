<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionCommand;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionSource;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Http\Controllers\Controller;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RosterSuggestionController extends Controller
{
    public function __construct(
        private readonly RosterSuggestionService $suggestions,
        private readonly RosterSuggestionApplier $applier,
        private readonly RosteringFeatureFlags $featureFlags,
        private readonly UserSiteAccessService $siteAccess,
    ) {
    }

    public function show(Request $request, RosterSuggestionRun $run)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.autoSchedule'), 403);
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $this->assertCanAccessRun($run, $auth);

        $run->load([
            'site:id,name',
            'requestedBy:id,name',
            'suggestions' => fn ($query) => $query->orderBy('shift_id')->orderBy('rank'),
        ]);
        // Recorded choices and totals are history. Current source visibility is
        // separately scoped; a run's Site must never rescue a foreign Client.
        $query = Shift::query()->employeeDuties()->whereKey($run->suggestions->pluck('shift_id')->unique());
        $this->siteAccess->applyShiftScope($query, $auth, ['shifts.manageAny']);
        $query->where(fn (Builder $site) => $site->where('site_id', $run->site_id)
            ->orWhere(fn (Builder $fallback) => $fallback->whereNull('site_id')
                ->whereHas('client', fn (Builder $client) => $client->where('site_id', $run->site_id))));
        $shifts = $query->with([
            'client:id,site_id,first_name,last_name',
            'site:id,name',
            'client.site:id,name',
            'serviceContext:id,site_id,name',
            'staff:id,name',
        ])->get()->filter(fn (Shift $shift) => $this->hasCurrentSourceForRun($shift, $run))->keyBy('id');
        $visible = $run->suggestions->filter(fn (RosterSuggestion $row) => $shifts->has($row->shift_id));
        $visible->load('candidate:id,name,email');

        return inertia('operations/rostering/suggestions/Show', [
            'worker_timezone' => (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC'),
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'strategy' => $run->strategy,
                'week_start' => $run->week_start->toDateString(),
                'week_end' => $run->week_end->toDateString(),
                'site' => $run->site ? ['id' => $run->site->id, 'name' => $run->site->name] : null,
                'requested_by' => $run->requestedBy?->name,
                'totals' => $run->totals ?? [],
                'parameters' => $run->parameters ?? [],
                'expires_at' => optional($run->expires_at)->toIso8601String(),
                'failure_message' => filled($run->failure_message) ? 'Suggestions could not be generated. Reload or generate a new run.' : null,
                'is_expired' => $run->isExpired(),
                // Entry permission only: the writer rechecks all accepted rows.
                'can' => ['apply_accepted' => true],
                'urls' => ['apply_accepted' => route('operations.rostering.suggestions.apply_accepted', $run)],
            ],
            'suggestion_visibility' => [
                'basis' => 'current_canonical_run_site',
                'recorded_count' => $run->suggestions->count(),
                'visible_count' => $visible->count(),
                'withheld_count' => $run->suggestions->count() - $visible->count(),
            ],
            'suggestions' => $visible->map(function (RosterSuggestion $suggestion) use ($shifts, $run): array {
                $shift = $shifts->get($suggestion->shift_id);
                $available = $suggestion->candidate !== null;
                $canApply = $available && ! $run->isExpired()
                    && in_array($suggestion->status, [RosterSuggestion::STATUS_SUGGESTED, RosterSuggestion::STATUS_ACCEPTED], true)
                    && $shift->user_id === null && ! in_array($shift->status, ['completed', 'cancelled'], true)
                    && $shift->client_id !== null && (int) $shift->site_id === (int) $run->site_id;
                $canAccept = ! $run->isExpired();
                $context = $shift->serviceContext;
                $source = RosterSuggestionSource::single($run, $suggestion, $shift);

                return [
                    'id' => $suggestion->id,
                    'shift_id' => $suggestion->shift_id,
                    'candidate_user_id' => $suggestion->candidate_user_id,
                    'source_revision' => $source['source_revision'],
                    'expected_source' => $source,
                    'rank' => $suggestion->rank,
                    'score' => (float) $suggestion->score,
                    'status' => $suggestion->status,
                    'reasons' => $suggestion->reasons ?? [],
                    'eligibility_snapshot' => $suggestion->eligibility_snapshot ?? [],
                    'current_source' => [
                        'status' => $available ? 'available' : 'unavailable',
                        'reason' => $available ? null : 'The suggested worker is no longer available. Reload or generate new suggestions.',
                    ],
                    // These are known current source/entry prerequisites, not
                    // an eligibility assertion or a promise of assignment.
                    'can' => ['accept' => $canAccept, 'dismiss' => true, 'apply' => $canApply],
                    'urls' => [
                        'accept' => $canAccept ? route('operations.rostering.suggestions.accept', $suggestion) : null,
                        'dismiss' => route('operations.rostering.suggestions.dismiss', $suggestion),
                        'apply' => $canApply ? route('operations.rostering.suggestions.apply', $suggestion) : null,
                    ],
                    'candidate' => $suggestion->candidate ? [
                        'id' => $suggestion->candidate->id,
                        'name' => $suggestion->candidate->name,
                        'email' => $suggestion->candidate->email,
                    ] : null,
                    'shift' => [
                        'id' => $shift->id,
                        'starts_at' => optional($shift->starts_at)->toIso8601String(),
                        'ends_at' => optional($shift->ends_at)->toIso8601String(),
                        'status' => $shift->status,
                        'client' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
                        'site' => ($shift->site ?? $shift->client?->site)?->name,
                        'service_context' => $context && ($context->site_id === null || (int) $context->site_id === (int) $run->site_id)
                            ? $context->name : null,
                        'current_staff' => $shift->staff?->name,
                    ],
                ];
            })->values(),
        ]);
    }

    public function accept(Request $request, RosterSuggestion $suggestion)
    {
        $request->session()->forget('roster_suggestion_result');
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $execution = app(RosterSuggestionCommand::class)->execute($request, 'accept', $suggestion);
        $result = $execution['result'];
        if ($result->outcome === 'expired_marked_stale') {
            if (! $execution['modern'] || $execution['receipt'] === null) {
                abort(422, 'This roster suggestion has expired. Generate a fresh run before applying it.');
            }

            return back()->setStatusCode(303)->with('warning', 'This roster suggestion has expired. Generate a fresh run before applying it.')
                ->with('roster_suggestion_result', $execution['receipt']);
        }

        $response = $execution['modern'] && $execution['receipt'] === null
            ? back()->with('warning', 'The command result could not be confirmed. Reload before acting again.')
            : back()->with('success', __('rostering.suggestions.accepted', ['id' => $result->suggestionId]));
        if ($execution['modern']) {
            $response->setStatusCode(303);
        }
        if ($execution['receipt'] !== null) {
            $response->with('roster_suggestion_result', $execution['receipt']);
        }

        return $response;
    }

    public function dismiss(Request $request, RosterSuggestion $suggestion)
    {
        $request->session()->forget('roster_suggestion_result');
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $execution = app(RosterSuggestionCommand::class)->execute($request, 'dismiss', $suggestion);
        $result = $execution['result'];
        $response = $execution['modern'] && $execution['receipt'] === null
            ? back()->with('warning', 'The command result could not be confirmed. Reload before acting again.')
            : back()->with('warning', __('rostering.suggestions.dismissed', ['id' => $result->suggestionId]));
        if ($execution['modern']) {
            $response->setStatusCode(303);
        }
        if ($execution['receipt'] !== null) {
            $response->with('roster_suggestion_result', $execution['receipt']);
        }

        return $response;
    }

    public function apply(Request $request, RosterSuggestion $suggestion)
    {
        $request->session()->forget('roster_suggestion_result');
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $execution = app(RosterSuggestionCommand::class)->execute($request, 'apply', $suggestion);
        $result = $execution['result'];
        $response = $execution['modern'] && $execution['receipt'] === null
            ? back()->with('warning', 'The command result could not be confirmed. Reload before acting again.')
            : back()->with('success', __('rostering.suggestions.applied'));
        if ($execution['modern']) {
            $response->setStatusCode(303);
        }
        if ($execution['receipt'] !== null) {
            $response->with('roster_suggestion_result', $execution['receipt']);
        }

        return $response;
    }

    public function applyAccepted(Request $request, RosterSuggestionRun $run)
    {
        $request->session()->forget('roster_suggestion_result');
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $execution = app(RosterSuggestionCommand::class)->execute($request, 'apply_accepted', $run);
        $result = $execution['result'];
        $counts = $result->counts;
        if ($execution['modern']) {
            $response = match (true) {
                $execution['receipt'] === null => back()->with('warning', 'The command result could not be confirmed. Reload before acting again.'),
                $result->disposition === 'empty' => back()->with('info', 'There are no accepted suggestions to apply. Reload for current choices.'),
                $result->disposition === 'preflight_no_change' => back()->with('warning', 'No assignments were applied. Reload and review the current suggestions.'),
                default => back()->with('success', __('rostering.suggestions.bulk_applied', $counts)),
            };
            $response->setStatusCode(303);
        } else {
            $response = back()->with($counts['failed'] > 0 ? 'warning' : 'success', __('rostering.suggestions.bulk_applied', $counts));
        }
        if ($execution['receipt'] !== null) {
            $response->with('roster_suggestion_result', $execution['receipt']);
        }

        return $response;
    }

    private function hasCurrentSourceForRun(Shift $shift, RosterSuggestionRun $run): bool
    {
        $client = $shift->client;
        if ($shift->client_id !== null && (! $client || (int) $client->id !== (int) $shift->client_id
            || (int) $client->site_id !== (int) $run->site_id)) {
            return false;
        }
        $site = $shift->site_id === null ? $client?->site : $shift->site;

        return $site && (int) $site->id === (int) $run->site_id;
    }

    private function authorizeSuggestion(RosterSuggestion $suggestion, User $actor): void
    {
        $suggestion->loadMissing('run');

        abort_unless($suggestion->run instanceof RosterSuggestionRun, 403);
        $this->assertCanAccessRun($suggestion->run, $actor);
    }

    private function assertCanAccessRun(RosterSuggestionRun $run, User $actor): void
    {
        $this->siteAccess->assertCanAccessSiteId(
            $actor,
            $run->site_id ? (int) $run->site_id : null,
            ['shifts.manageAny'],
        );
    }
}
