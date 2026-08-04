<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Http\Controllers\Controller;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\User;
use App\Services\UserSiteAccessService;
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
            'suggestions' => fn ($query) => $query
                ->with([
                    'candidate:id,name,email',
                    'shift.client:id,first_name,last_name',
                    'shift.site:id,name',
                    'shift.serviceContext:id,name',
                    'shift.staff:id,name',
                ])
                ->orderBy('shift_id')
                ->orderBy('rank'),
        ]);

        return inertia('operations/rostering/suggestions/Show', [
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
                'failure_message' => $run->failure_message,
                'is_expired' => $run->isExpired(),
            ],
            'suggestions' => $run->suggestions->map(fn (RosterSuggestion $suggestion) => [
                'id' => $suggestion->id,
                'shift_id' => $suggestion->shift_id,
                'candidate_user_id' => $suggestion->candidate_user_id,
                'rank' => $suggestion->rank,
                'score' => (float) $suggestion->score,
                'status' => $suggestion->status,
                'reasons' => $suggestion->reasons ?? [],
                'eligibility_snapshot' => $suggestion->eligibility_snapshot ?? [],
                'candidate' => $suggestion->candidate ? [
                    'id' => $suggestion->candidate->id,
                    'name' => $suggestion->candidate->name,
                    'email' => $suggestion->candidate->email,
                ] : null,
                'shift' => $suggestion->shift ? [
                    'id' => $suggestion->shift->id,
                    'starts_at' => optional($suggestion->shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($suggestion->shift->ends_at)->toIso8601String(),
                    'status' => $suggestion->shift->status,
                    'client' => $suggestion->shift->client
                        ? trim($suggestion->shift->client->first_name.' '.$suggestion->shift->client->last_name)
                        : null,
                    'site' => $suggestion->shift->site?->name,
                    'service_context' => $suggestion->shift->serviceContext?->name,
                    'current_staff' => $suggestion->shift->staff?->name,
                ] : null,
            ])->values(),
        ]);
    }

    public function accept(Request $request, RosterSuggestion $suggestion)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.autoSchedule'), 403);
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $this->authorizeSuggestion($suggestion, $auth);

        $updated = $this->suggestions->accept($suggestion, $auth);

        return back()->with('success', __('rostering.suggestions.accepted', ['id' => $updated->id]));
    }

    public function dismiss(Request $request, RosterSuggestion $suggestion)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.autoSchedule'), 403);
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $this->authorizeSuggestion($suggestion, $auth);

        $updated = $this->suggestions->dismiss($suggestion, $auth);

        return back()->with('warning', __('rostering.suggestions.dismissed', ['id' => $updated->id]));
    }

    public function apply(Request $request, RosterSuggestion $suggestion)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.autoSchedule'), 403);
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $this->authorizeSuggestion($suggestion, $auth);

        $this->applier->applyOne($suggestion, $auth);

        return back()->with('success', __('rostering.suggestions.applied'));
    }

    public function applyAccepted(Request $request, RosterSuggestionRun $run)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.autoSchedule'), 403);
        abort_unless($this->featureFlags->autoScheduleEnabled(), 404);
        $this->assertCanAccessRun($run, $auth);

        $results = $this->applier->applyAccepted($run, $auth);

        return back()->with(
            $results['failed'] > 0 ? 'warning' : 'success',
            __('rostering.suggestions.bulk_applied', $results),
        );
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
