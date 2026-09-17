<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Services\GovernanceWorkQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

        $filters = $this->filters($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;

        $feed = $this->workQuery->queryFeed($viewer, $filters, $perPage, $page);
        $feed['pagination']['links'] = $this->paginationLinks($request, $feed['pagination']);

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

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $feed = $this->workQuery->queryFeed($viewer, $this->filters($request), $perPage, $page);

        return response()->json($feed);
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        return [
            'kind' => $request->query('kind', 'all'),
            'status' => $request->query('status', 'pending'),
            'due' => $request->query('due', 'all'),
            'committee' => $request->query('committee'),
            'search' => $request->query('search'),
        ];
    }

    /**
     * Laravel-style page links for the shared LaravelPagination component,
     * keeping the viewer's current filters in every link.
     *
     * @param  array{total: int, per_page: int, current_page: int, last_page: int}  $pagination
     * @return array<int, array{url: ?string, label: string, active: bool}>
     */
    protected function paginationLinks(Request $request, array $pagination): array
    {
        $paginator = new LengthAwarePaginator(
            [],
            (int) $pagination['total'],
            max(1, (int) $pagination['per_page']),
            (int) $pagination['current_page'],
            ['path' => $request->url(), 'query' => $request->except('page')],
        );

        return $paginator->linkCollection()->toArray();
    }
}
