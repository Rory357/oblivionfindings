<?php

namespace App\Http\Controllers;

use App\Support\ReportCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModuleReportController extends Controller
{
    public function show(Request $request, string $module)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $definition = ReportCatalog::find($module);
        abort_unless(is_array($definition), 404);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->buildQuery($definition, $filters);
        $columns = $this->availableColumns($definition);

        $rows = $query
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Model $row): array => $this->serializeRow($row, $columns));

        return inertia('reports/module', [
            'module' => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'route' => $definition['route'],
                'columns' => $columns,
            ],
            'filters' => [
                'search' => $filters['search'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'statuses' => $this->statusOptions($definition),
            'rows' => $rows,
        ]);
    }

    public function export(Request $request, string $module): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $definition = ReportCatalog::find($module);
        abort_unless(is_array($definition), 404);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:80'],
        ]);

        $query = $this->buildQuery($definition, $filters);
        $columns = $this->availableColumns($definition);
        $labels = array_values($columns);

        $filename = sprintf(
            '%s_report_%s.csv',
            $definition['key'],
            now()->format('Ymd_His')
        );

        return response()->streamDownload(function () use ($query, $columns, $labels) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, $labels);

            $query->chunk(500, function ($records) use ($out, $columns) {
                foreach ($records as $record) {
                    $row = $this->serializeRow($record, $columns);
                    $this->putCsv($out, array_values($row));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $filters
     */
    private function buildQuery(array $definition, array $filters): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $model = new $modelClass();

        $table = $model->getTable();
        $dateField = (string) ($definition['date_field'] ?? 'created_at');
        $columns = $this->availableColumns($definition);
        $searchColumns = array_values(array_filter(
            $definition['search_columns'] ?? [],
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        $query = $modelClass::query()->select(array_keys($columns));

        if (!empty($filters['search']) && count($searchColumns) > 0) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($searchColumns, $term): void {
                foreach ($searchColumns as $index => $column) {
                    if ($index === 0) {
                        $inner->where($column, 'like', '%' . $term . '%');
                        continue;
                    }
                    $inner->orWhere($column, 'like', '%' . $term . '%');
                }
            });
        }

        if (!empty($filters['date_from']) && Schema::hasColumn($table, $dateField)) {
            $query->whereDate($dateField, '>=', (string) $filters['date_from']);
        }

        if (!empty($filters['date_to']) && Schema::hasColumn($table, $dateField)) {
            $query->whereDate($dateField, '<=', (string) $filters['date_to']);
        }

        if (!empty($filters['status']) && Schema::hasColumn($table, 'status')) {
            $query->where('status', (string) $filters['status']);
        }

        if (Schema::hasColumn($table, $dateField)) {
            $query->orderByDesc($dateField);
        } elseif (Schema::hasColumn($table, 'created_at')) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc($model->getKeyName());
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, string>
     */
    private function availableColumns(array $definition): array
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $table = (new $modelClass())->getTable();

        /** @var array<string, string> $configured */
        $configured = $definition['columns'] ?? [];

        return array_filter(
            $configured,
            fn (string $label, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    private function serializeRow(Model $model, array $columns): array
    {
        $row = [];

        foreach (array_keys($columns) as $column) {
            $value = $model->getAttribute($column);
            if ($value instanceof \DateTimeInterface) {
                $row[$column] = $value->format('Y-m-d H:i:s');
                continue;
            }
            if (is_array($value)) {
                $row[$column] = json_encode($value);
                continue;
            }
            if (is_bool($value)) {
                $row[$column] = $value ? 'yes' : 'no';
                continue;
            }
            $row[$column] = $value === null ? '' : (string) $value;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, string>
     */
    private function statusOptions(array $definition): array
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $table = (new $modelClass())->getTable();

        if (!Schema::hasColumn($table, 'status')) {
            return [];
        }

        return $modelClass::query()
            ->whereNotNull('status')
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->limit(100)
            ->pluck('status')
            ->filter(fn ($status): bool => is_string($status) && trim($status) !== '')
            ->values()
            ->all();
    }
}

