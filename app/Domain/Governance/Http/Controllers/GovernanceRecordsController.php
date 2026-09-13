<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceRecordsController extends Controller
{
    public function __construct(
        protected GovernanceRecordAccessService $recordAccess
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        if (! $viewer || $viewer->approved_at === null) {
            abort(403, 'Unauthorized.');
        }

        $tab = $request->query('tab', 'all');
        $search = $request->query('search');

        $canViewDocuments = $viewer->canDo('governance.documents.view');
        $canViewMeetings = $viewer->canDo('governance.meetings.view');
        $canViewResolutions = $viewer->canDo('governance.resolutions.view');
        $canViewPolicies = $viewer->canDo('governance.policies.view');

        // 1. Documents (strictly gated - if false, never query or disclose metadata)
        $documents = null;
        if ($canViewDocuments) {
            $docQuery = GovernanceDocument::query()
                ->when($request->category, fn ($q, $cat) => $q->where('document_type', $cat))
                ->when($search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
                ->orderByDesc('updated_at');

            $documents = $docQuery->paginate(15, ['*'], 'doc_page')
                ->withQueryString()
                ->through(fn (GovernanceDocument $doc) => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'category' => $doc->document_type,
                    'file_name' => basename($doc->file_path),
                    'file_size' => (int) ($doc->file_size ?? 0),
                    'is_confidential' => false,
                    'version' => (int) $doc->version_number,
                    'updated_at' => $doc->updated_at?->toIso8601String(),
                ]);
        }

        // 2. Historical Meetings & Minutes
        $meetings = null;
        if ($canViewMeetings) {
            $meetQuery = GovernanceMeeting::query()
                ->with(['chair.user', 'secretary.user', 'minutes'])
                ->where('scheduled_at', '<', now())
                ->when($search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
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

        // 3. Historical Resolutions / Decisions
        $resolutions = null;
        if ($canViewResolutions) {
            $resQuery = Resolution::query()
                ->with(['meeting', 'committee'])
                ->whereIn('status', ['carried', 'implemented', 'closed', 'archived'])
                ->when($search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
                ->orderByDesc('created_at');

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
                    'created_at' => $r->created_at?->toIso8601String(),
                ]);
        }

        // 4. Approved Policies
        $policies = null;
        if ($canViewPolicies) {
            $polQuery = GovernancePolicy::query()
                ->where('status', 'approved')
                ->when($search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
                ->orderBy('title');

            $policies = $polQuery->paginate(15, ['*'], 'pol_page')
                ->withQueryString()
                ->through(fn (GovernancePolicy $p) => [
                    'id' => $p->id,
                    'policy_code' => $p->policy_code,
                    'title' => $p->title,
                    'category' => $p->category,
                    'version_number' => $p->version_number,
                    'effective_from' => $p->effective_from?->toDateString(),
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
            ],
            'documents' => $documents,
            'meetings' => $meetings,
            'resolutions' => $resolutions,
            'policies' => $policies,
            'categories' => [
                ['value' => 'constitution', 'label' => 'Constitution / Charter'],
                ['value' => 'terms_of_reference', 'label' => 'Terms Of Reference'],
                ['value' => 'policy', 'label' => 'Board Policy'],
                ['value' => 'procedure', 'label' => 'Procedure'],
                ['value' => 'template', 'label' => 'Template'],
                ['value' => 'report', 'label' => 'Report'],
                ['value' => 'certificate', 'label' => 'Certificate'],
            ],
        ]);
    }
}
