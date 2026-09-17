<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Records: search everything the board has done — past meetings, resolutions,
 * policies, files, and (for members who can see them) approved budgets and
 * strategic plans. Each section is gated by its own view permission.
 */
class GovernanceRecordsController extends Controller
{
    private const RESOLUTION_STATUSES = ['carried', 'implemented', 'closed', 'archived'];

    private const APPROVED_BUDGET_STATUSES = ['approved', 'closed', 'archived'];

    private const APPROVED_PLAN_STATUSES = ['approved', 'active', 'superseded', 'archived', 'completed'];

    public function __construct(
        protected GovernanceRecordAccessService $recordAccess
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        if (! $viewer || $viewer->approved_at === null) {
            abort(403, 'Unauthorized.');
        }

        $tab = (string) $request->query('tab', 'all');
        $search = trim((string) $request->query('search', ''));
        $search = $search !== '' ? $search : null;
        $like = $search !== null ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%' : null;

        $canViewDocuments = $viewer->canDo('governance.documents.view');
        $canViewMeetings = $viewer->canDo('governance.meetings.view');
        $canViewResolutions = $viewer->canDo('governance.resolutions.view');
        $canViewPolicies = $viewer->canDo('governance.policies.view');
        $canViewBudgets = $viewer->canDo('governance.budgets.view');
        $canViewStrategy = $viewer->canDo('governance.strategy.view');

        // 1. Documents (strictly gated - if false, never query or disclose metadata)
        $documents = null;
        if ($canViewDocuments) {
            $documents = GovernanceDocument::query()
                ->when($request->category, fn ($q, $cat) => $q->where('document_type', $cat))
                ->when($like, fn ($q) => $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $like)
                    ->orWhere('original_name', 'like', $like)))
                ->orderByDesc('updated_at')
                ->paginate(15, ['*'], 'doc_page')
                ->withQueryString()
                ->through(fn (GovernanceDocument $doc) => GovernanceDocumentController::presentListItem($doc));
        }

        // 2. Past meetings and their minutes. Cancelled meetings were never held.
        $meetings = null;
        if ($canViewMeetings) {
            $meetQuery = GovernanceMeeting::query()
                ->with(['minutes'])
                ->where('scheduled_at', '<', now())
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
                ->when($like, fn ($q) => $q->where('title', 'like', $like))
                ->orderByDesc('scheduled_at');

            $meetQuery = $this->recordAccess->scopeMeetings($meetQuery, $viewer);

            $meetings = $meetQuery->paginate(15, ['*'], 'meeting_page')
                ->withQueryString()
                ->through(fn (GovernanceMeeting $m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'meeting_type' => $m->meeting_type,
                    'scheduled_at' => $m->scheduled_at?->toIso8601String(),
                    'status' => $m->status,
                    'has_minutes' => $m->minutes !== null,
                    'minutes_status' => $m->minutes?->status,
                    'minutes_version' => $m->minutes?->version_number,
                ]);
        }

        // 3. Resolutions the board has finished deciding.
        $resolutions = null;
        if ($canViewResolutions) {
            $resQuery = Resolution::query()
                ->with(['meeting', 'committee'])
                ->whereIn('status', self::RESOLUTION_STATUSES)
                ->when($like, fn ($q) => $q->where('title', 'like', $like))
                ->orderByRaw('COALESCE(closed_at, updated_at) DESC')
                ->orderByDesc('id');

            $resQuery = $this->recordAccess->scopeResolutions($resQuery, $viewer);

            $resolutions = $resQuery->paginate(15, ['*'], 'res_page')
                ->withQueryString()
                ->through(fn (Resolution $r) => [
                    'id' => $r->id,
                    'resolution_reference' => $r->resolution_reference,
                    'title' => $r->title,
                    'status' => $r->status,
                    'outcome' => $r->outcome,
                    'voting_threshold' => $r->voting_threshold,
                    'meeting_title' => $r->meeting?->title,
                    // When the board decided, not when the draft was written.
                    'decided_at' => ($r->closed_at ?? $r->updated_at)?->toIso8601String(),
                ]);
        }

        // 4. Approved policies
        $policies = null;
        if ($canViewPolicies) {
            $policies = GovernancePolicy::query()
                ->where('status', 'approved')
                ->when($like, fn ($q) => $q->where('title', 'like', $like))
                ->orderBy('title')
                ->paginate(15, ['*'], 'pol_page')
                ->withQueryString()
                ->through(fn (GovernancePolicy $p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'category' => $p->category,
                    'version_number' => $p->version_number,
                    'effective_from' => $p->effective_from?->toDateString(),
                ]);
        }

        // 5. Approved budgets — read-only, for members who can see budgets.
        $budgets = null;
        if ($canViewBudgets) {
            $budgets = Budget::query()
                ->whereIn('status', self::APPROVED_BUDGET_STATUSES)
                ->when($like, fn ($q) => $q->where('title', 'like', $like))
                ->orderByDesc('fiscal_year')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'budget_page')
                ->withQueryString()
                ->through(fn (Budget $budget) => [
                    'id' => $budget->id,
                    'title' => $budget->title,
                    'fiscal_year' => $budget->fiscal_year,
                    'status' => $budget->status,
                    'approved_at' => $budget->approved_by_board_at?->toIso8601String(),
                ]);
        }

        // 6. Approved strategic plans — read-only, for members who can see the plan.
        $plans = null;
        if ($canViewStrategy) {
            $plans = StrategicPlan::query()
                ->whereIn('status', self::APPROVED_PLAN_STATUSES)
                ->when($like, fn ($q) => $q->where('title', 'like', $like))
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'plan_page')
                ->withQueryString()
                ->through(fn (StrategicPlan $plan) => [
                    'id' => $plan->id,
                    'title' => $plan->title,
                    'status' => $plan->status,
                    'version_number' => $plan->version_number,
                    'period_start' => $plan->period_start?->toDateString(),
                    'period_end' => $plan->period_end?->toDateString(),
                ]);
        }

        return Inertia::render('Governance/Records/Index', [
            'tab' => $tab,
            'search' => $search,
            'category' => $canViewDocuments ? $request->query('category') : null,
            'capabilities' => [
                'documents' => $canViewDocuments,
                'meetings' => $canViewMeetings,
                'resolutions' => $canViewResolutions,
                'policies' => $canViewPolicies,
                'budgets' => $canViewBudgets,
                'plans' => $canViewStrategy,
            ],
            'documents' => $documents,
            'meetings' => $meetings,
            'resolutions' => $resolutions,
            'policies' => $policies,
            'budgets' => $budgets,
            'plans' => $plans,
            'categories' => GovernanceDocument::typeOptions(),
        ]);
    }
}
