<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Services\GovernanceCalendarQuery;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceCalendarController extends Controller
{
    /**
     * Plain source names for the shared calendar's filters. Mirrors
     * GOVERNANCE_CALENDAR_SOURCES in resources/js/lib/governance-calendar-adapter.ts.
     */
    private const SOURCE_LABELS = [
        'meetings' => [
            'label' => 'Meetings',
            'short' => 'Meetings',
            'icon' => 'CalendarDays',
            'origin' => 'Meetings',
            'note' => 'Board or committee meeting',
        ],
        'decisions' => [
            'label' => 'Voting deadlines',
            'short' => 'Voting',
            'icon' => 'Vote',
            'origin' => 'Resolutions',
            'note' => 'When voting on a resolution closes',
        ],
        'obligations' => [
            'label' => 'Requirements',
            'short' => 'Requirements',
            'icon' => 'ShieldCheck',
            'origin' => 'Compliance',
            'note' => 'When a compliance requirement is due',
        ],
        'policies' => [
            'label' => 'Policy reviews',
            'short' => 'Policies',
            'icon' => 'BookOpen',
            'origin' => 'Policies',
            'note' => 'When a policy is due for review',
        ],
    ];

    public function __construct(
        private readonly GovernanceCalendarQuery $calendarQuery,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // Meetings are only stored by viewers who can manage them.
        $canCreate = $user->canDo('governance.meetings.manage');

        // Only offer the sources whose registers this viewer can open.
        $allowed = $this->calendarQuery->sourcesFor($user);
        $sources = array_map(
            fn (string $key) => ['key' => $key, 'group' => 'auto', ...self::SOURCE_LABELS[$key]],
            $allowed,
        );

        $initialSource = $request->query('source');
        $initialSources = is_string($initialSource) && in_array($initialSource, $allowed, true)
            ? [$initialSource]
            : $allowed;

        return Inertia::render('Governance/Calendar/Index', [
            'sources' => $sources,
            'initialSources' => $initialSources,
            'canCreate' => $canCreate,
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date',
            'sources' => 'nullable|string',
            'committee_id' => 'nullable|integer',
        ]);

        $start = Carbon::parse($validated['start']);
        $end = Carbon::parse($validated['end']);
        $sources = ! empty($validated['sources'])
            ? explode(',', $validated['sources'])
            : null;
        $committeeId = isset($validated['committee_id']) ? (int) $validated['committee_id'] : null;

        $result = $this->calendarQuery->queryItems(
            $start,
            $end,
            $request->user(),
            $sources,
            $committeeId,
        );

        return response()->json($result);
    }
}
