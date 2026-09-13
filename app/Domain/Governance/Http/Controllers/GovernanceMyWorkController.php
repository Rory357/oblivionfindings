<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Services\GovernanceWorkQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceMyWorkController extends Controller
{
    public function __construct(
        protected GovernanceWorkQuery $workQuery
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        if (! $viewer || $viewer->approved_at === null) {
            abort(403, 'Unauthorized.');
        }

        $filters = [
            'kind' => $request->query('kind', 'all'),
            'status' => $request->query('status', 'pending'),
            'due' => $request->query('due', 'all'),
            'committee' => $request->query('committee'),
            'search' => $request->query('search'),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;

        $feed = $this->workQuery->queryFeed($viewer, $filters, $perPage, $page);

        return Inertia::render('Governance/MyWork/Index', [
            'feed' => $feed,
            'filters' => $filters,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer || $viewer->approved_at === null) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $filters = [
            'kind' => $request->query('kind', 'all'),
            'status' => $request->query('status', 'pending'),
            'due' => $request->query('due', 'all'),
            'committee' => $request->query('committee'),
            'search' => $request->query('search'),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $feed = $this->workQuery->queryFeed($viewer, $filters, $perPage, $page);

        return response()->json($feed);
    }
}
