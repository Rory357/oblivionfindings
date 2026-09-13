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
    public function __construct(
        private readonly GovernanceCalendarQuery $calendarQuery,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $canCreate = $user->canDo('governance.meetings.create')
            || $user->hasRole('admin', 'board_chair', 'board_secretary');

        $sources = [
            [
                'key' => 'meetings',
                'label' => 'Meetings',
                'short' => 'Meetings',
                'group' => 'manual',
                'icon' => 'CalendarDays',
                'origin' => 'Governance meetings',
            ],
            [
                'key' => 'decisions',
                'label' => 'Decision deadlines',
                'short' => 'Decisions',
                'group' => 'auto',
                'icon' => 'Vote',
                'origin' => 'Board resolutions',
            ],
            [
                'key' => 'obligations',
                'label' => 'Obligations',
                'short' => 'Obligations',
                'group' => 'auto',
                'icon' => 'ShieldCheck',
                'origin' => 'Compliance register',
            ],
            [
                'key' => 'policies',
                'label' => 'Policy reviews',
                'short' => 'Policies',
                'group' => 'auto',
                'icon' => 'BookOpen',
                'origin' => 'Policy register',
            ],
        ];

        $initialSource = $request->query('source');
        $initialSources = $initialSource ? [$initialSource] : ['meetings', 'decisions', 'obligations', 'policies'];

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
