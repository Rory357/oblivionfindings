<?php

namespace App\Http\Controllers;

use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-wide "All Tasks" dashboard: every open incident, corrective
 * action, alert, concern and follow-up across the app in one
 * permission-filtered, ticket-numbered list. Read-only — each row
 * deep-links back to the module that owns the record.
 */
class AllTasksController extends Controller
{
    public function index(Request $request, TaskAggregator $aggregator): Response|StreamedResponse
    {
        $user = $request->user();

        $filters = [
            'sources' => $this->csv($request->query('sources')),
            'severity' => $this->csv($request->query('severity')),
            'bucket' => $this->csv($request->query('bucket')),
            'assigned' => in_array($request->query('assigned'), ['me', 'unassigned'], true)
                ? $request->query('assigned')
                : null,
            'overdue' => $request->boolean('overdue'),
            'due' => $request->query('due') === 'week' ? 'week' : null,
            'q' => ($q = trim((string) $request->query('q', ''))) === '' ? null : $q,
            'include_done' => $request->boolean('done'),
        ];

        // One provider pass; stats stay stable while filters slice the list.
        $items = $aggregator->itemsFor($user, ['include_done' => $filters['include_done']]);
        $filtered = $aggregator->filterItems($items, $user, $filters);

        if ($request->query('format') === 'csv') {
            return $this->exportCsv($filtered);
        }

        return Inertia::render('tasks/index', [
            'items' => array_map(fn (TaskItem $item) => $item->toArray(), $filtered),
            'stats' => $aggregator->stats($items, $user),
            'sources' => $aggregator->sourcesFor($user),
            'filters' => $filters,
        ]);
    }

    /**
     * Stream the current (filtered) queue as a spreadsheet-safe CSV.
     *
     * @param  TaskItem[]  $items
     */
    private function exportCsv(array $items): StreamedResponse
    {
        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');

            $this->putCsv($out, [
                'Ticket', 'Title', 'Type', 'Module', 'Severity', 'Status',
                'Assignee', 'Client', 'Site', 'Due', 'Overdue', 'Created', 'Link',
            ]);

            foreach ($items as $item) {
                $this->putCsv($out, [
                    $item->ref,
                    $item->title,
                    $item->type,
                    $item->sourceLabel,
                    $item->severity,
                    $item->status,
                    $item->assignee['name'] ?? '',
                    $item->client['name'] ?? '',
                    $item->site['name'] ?? '',
                    $item->dueAt,
                    $item->isOverdue() ? 'yes' : 'no',
                    $item->createdAt,
                    $item->link,
                ]);
            }

            fclose($out);
        }, 'all-tasks-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
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
