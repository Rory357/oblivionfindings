<?php

namespace App\Http\Controllers;

use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company-wide "All Tasks" dashboard: every open incident, corrective
 * action, alert, concern and follow-up across the app in one
 * permission-filtered, ticket-numbered list. Read-only — each row
 * deep-links back to the module that owns the record.
 */
class AllTasksController extends Controller
{
    public function index(Request $request, TaskAggregator $aggregator): Response
    {
        $user = $request->user();

        $filters = [
            'sources' => $this->csv($request->query('sources')),
            'severity' => $this->csv($request->query('severity')),
            'bucket' => $this->csv($request->query('bucket')),
            'assigned' => $request->query('assigned') === 'me' ? 'me' : null,
            'overdue' => $request->boolean('overdue'),
            'q' => ($q = trim((string) $request->query('q', ''))) === '' ? null : $q,
            'include_done' => $request->boolean('done'),
        ];

        // One provider pass; stats stay stable while filters slice the list.
        $items = $aggregator->itemsFor($user, ['include_done' => $filters['include_done']]);

        return Inertia::render('tasks/index', [
            'items' => array_map(
                fn (TaskItem $item) => $item->toArray(),
                $aggregator->filterItems($items, $user, $filters),
            ),
            'stats' => $aggregator->stats($items, $user),
            'sources' => $aggregator->sourcesFor($user),
            'filters' => $filters,
        ]);
    }

    /**
     * @return string[]|null
     */
    private function csv(?string $value): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        return $parts === [] ? null : $parts;
    }
}
