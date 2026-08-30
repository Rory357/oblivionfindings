<?php

namespace App\Http\Controllers;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\AuditLog;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedicationAdministration;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Support\ReportCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModuleReportController extends Controller
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $medicationScope,
        private readonly BoardPackAccessService $boardPackAccess,
    ) {}

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

        $query = $this->buildQuery($definition, $filters, $user);
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
            'statuses' => $this->statusOptions($definition, $user),
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

        $query = $this->buildQuery($definition, $filters, $user);
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
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $filters
     */
    private function buildQuery(array $definition, array $filters, User $user): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $model = new $modelClass;

        $table = $model->getTable();
        $dateField = (string) ($definition['date_field'] ?? 'created_at');
        $columns = $this->availableColumns($definition);
        $searchColumns = array_values(array_filter(
            $definition['search_columns'] ?? [],
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        $query = $modelClass::query()->select(array_keys($columns));
        if ($this->medicationGovernance($definition) === 'administration') {
            $query->effectiveClinicalEvidence();
        }
        $this->applyMedicationGovernanceScope($query, $definition, $user);

        if (! empty($filters['search']) && count($searchColumns) > 0) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($searchColumns, $term): void {
                foreach ($searchColumns as $index => $column) {
                    if ($index === 0) {
                        $inner->where($column, 'like', '%'.$term.'%');

                        continue;
                    }
                    $inner->orWhere($column, 'like', '%'.$term.'%');
                }
            });
        }

        if (! empty($filters['date_from']) && Schema::hasColumn($table, $dateField)) {
            $query->whereDate($dateField, '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to']) && Schema::hasColumn($table, $dateField)) {
            $query->whereDate($dateField, '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['status']) && Schema::hasColumn($table, 'status')) {
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
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function availableColumns(array $definition): array
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $table = (new $modelClass)->getTable();

        /** @var array<string, string> $configured */
        $configured = $definition['columns'] ?? [];

        return array_filter(
            $configured,
            fn (string $label, string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<string, string>  $columns
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
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function statusOptions(array $definition, User $user): array
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $table = (new $modelClass)->getTable();

        if (! Schema::hasColumn($table, 'status')) {
            return [];
        }

        $query = $modelClass::query();
        if ($this->medicationGovernance($definition) === 'administration') {
            $query->effectiveClinicalEvidence();
        }
        $this->applyMedicationGovernanceScope($query, $definition, $user);

        return $query
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

    /** @param array<string, mixed> $definition */
    private function applyMedicationGovernanceScope(
        Builder $query,
        array $definition,
        User $user,
    ): void {
        $governance = $this->medicationGovernance($definition);
        if ($governance === 'general_audit') {
            $this->excludeMedicationAuditFamilies($query);
            $this->excludeBoardPackAuditUnlessManager($query, $user);

            return;
        }
        if (! in_array($governance, ['administration', 'controlled'], true)) {
            return;
        }

        $isControlledModule = $governance === 'controlled';
        $siteIds = $this->medicationScope->reportSiteIds(
            $user,
            controlled: $isControlledModule,
        );
        $this->medicationScope->scopeCanonicalClientMedicationRows(
            $query,
            $siteIds,
            allowNullMedication: false,
        );

        if (! $isControlledModule
            && ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $this->medicationScope->scopeWithoutControlledMedicationRows($query);
        }
    }

    /** @param array<string, mixed> $definition */
    private function medicationGovernance(array $definition): ?string
    {
        return match ($definition['model'] ?? null) {
            AuditLog::class => 'general_audit',
            ClientMedicationAdministration::class => 'administration',
            ClientControlledDrugDiscrepancy::class => 'controlled',
            default => null,
        };
    }

    private function excludeMedicationAuditFamilies(Builder $query): void
    {
        $query->where(function (Builder $nonMedication): void {
            $nonMedication->whereNull('auditable_type')
                ->orWhere(function (Builder $typed): void {
                    $typed->where('auditable_type', 'not like', '%Medication%')
                        ->where('auditable_type', 'not like', '%ControlledDrug%');
                });
        })->where(function (Builder $nonMedicationAction): void {
            $nonMedicationAction->whereNull('action')
                ->orWhere(function (Builder $action): void {
                    $action->where('action', 'not like', 'medication%')
                        ->where('action', 'not like', 'meds.%')
                        ->where('action', 'not like', 'emar.%')
                        ->where('action', 'not like', 'clientmedication%')
                        ->where('action', 'not like', 'clientcontrolleddrug%')
                        ->where('action', 'not like', 'controlled_drug%')
                        ->where('action', 'not like', 'cd.%')
                        ->where('action', 'not like', 'cd\_%');
                });
        });
    }

    private function excludeBoardPackAuditUnlessManager(Builder $query, User $user): void
    {
        if ($this->boardPackAccess->canManage($user)) {
            return;
        }

        $query->where(function (Builder $nonPack): void {
            $nonPack->whereNull('auditable_type')
                ->orWhereNotIn('auditable_type', [BoardPack::class, 'BoardPack']);
        })->where(function (Builder $nonPackAction): void {
            $nonPackAction->whereNull('action')
                ->orWhere(function (Builder $action): void {
                    $action->where('action', 'not like', 'boardpack.%')
                        ->where('action', 'not like', 'board_pack.%');
                });
        });
    }
}
