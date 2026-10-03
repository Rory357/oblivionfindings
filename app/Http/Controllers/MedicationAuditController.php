<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\Reporting\MedicationExportAudit;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportDataset;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicationAuditController extends Controller
{
    use SanitizesCsvOutput;

    private const AUDITABLE_TYPES = [
        ClientMedication::class,
        ClientMedicationAdministration::class,
        ClientControlledDrugEntry::class,
        ClientControlledDrugDiscrepancy::class,
        ClientBreakGlassAccess::class,
    ];

    private const CONTROLLED_ONLY_TYPES = [
        ClientControlledDrugEntry::class,
        ClientControlledDrugDiscrepancy::class,
    ];

    private const REQUIRED_MEDICATION_LINK_TYPES = [
        ClientMedicationAdministration::class,
        ClientControlledDrugEntry::class,
    ];

    public function __construct(
        private readonly MedicationGovernanceScopeService $governanceScope,
    ) {}

    /** @param array<int, int> $clientIds */
    private function baseQuery(array $clientIds, bool $canViewControlled): Builder
    {
        $auditableTypes = $canViewControlled
            ? self::AUDITABLE_TYPES
            : array_values(array_diff(self::AUDITABLE_TYPES, self::CONTROLLED_ONLY_TYPES));

        return AuditLog::query()
            ->with(['user:id,name', 'client:id,first_name,last_name'])
            ->whereIn('client_id', $clientIds)
            ->whereIn('auditable_type', $auditableTypes)
            ->whereHasMorph(
                'auditable',
                $auditableTypes,
                function (Builder $auditable, string $type) use ($canViewControlled): void {
                    $auditable->whereColumn(
                        $auditable->getModel()->qualifyColumn('client_id'),
                        'audit_logs.client_id',
                    );

                    if (in_array($type, self::REQUIRED_MEDICATION_LINK_TYPES, true)) {
                        $this->governanceScope->scopeCanonicalClientMedicationRows($auditable, null, false);
                    }

                    if ($type === ClientControlledDrugDiscrepancy::class) {
                        $this->governanceScope->scopeCanonicalClientMedicationRows($auditable, null);
                    }

                    if (! $canViewControlled && $type === ClientMedication::class) {
                        $auditable->where(function (Builder $classification): void {
                            $classification->where('controlled_drug', false)->orWhereNull('controlled_drug');
                        });
                    }

                    if (! $canViewControlled && $type === ClientMedicationAdministration::class) {
                        $this->governanceScope->scopeWithoutControlledMedicationRows($auditable);
                    }
                },
            )
            ->orderByDesc('id');
    }

    public function index(Request $request)
    {
        $period = $this->period($request);
        $user = $request->user();
        abort_unless($user, 403);
        $clientId = $request->integer('client_id') ?: null;
        $siteId = $request->integer('site_id') ?: null;
        $siteIds = $this->governanceScope->readerSiteIds(
            $user,
            'medications.audit.view',
            $siteId,
            $clientId,
        );
        $readerSiteIds = $siteId !== null ? [$siteId] : $siteIds;
        $access = app(MedicationReportAccess::class);
        $access->siteIds($user, $siteId, $clientId);
        $clientIds = $access->clientIds($user, $readerSiteIds);

        $q = $this->baseQuery(
            $clientIds,
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        $q->whereBetween('audit_logs.created_at', $period->bounds());

        $logs = $q->limit(200)->get()->map(fn ($l) => [
            'id' => $l->id,
            'created_at' => $l->created_at,
            'action' => $l->action,
            'auditable_type' => class_basename($l->auditable_type),
            'auditable_id' => $l->auditable_id,
            'client' => $l->client ? [
                'id' => $l->client->id,
                'name' => trim($l->client->first_name.' '.$l->client->last_name),
            ] : null,
            'user' => $l->user ? [
                'id' => $l->user->id,
                'name' => $l->user->name,
            ] : null,
            'meta' => $this->safeMeta($l->meta),
        ])->values();

        return inertia('emar/reports/history-logs', [
            'filters' => [
                'client_id' => $request->query('client_id'),
                'site_id' => $request->query('site_id'),
                'user_id' => $request->query('user_id'),
                'period' => $period->key,
                'date_from' => $period->from,
                'date_to' => $period->to,
            ],
            'logs' => $logs,
            'clients' => Client::query()->whereIn('id', $clientIds)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($client) => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]),
            'sites' => $this->governanceScope->sitePicker($siteIds),
            'can_export_history' => $user->canDo('medications.audit.export'),
            'export_purposes' => MedicationExportAudit::PURPOSES,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $period = $this->period($request);
        $user = $request->user();
        abort_unless($user, 403);
        $clientId = $request->integer('client_id') ?: null;
        $siteId = $request->integer('site_id') ?: null;
        $siteIds = $this->governanceScope->readerSiteIds(
            $user,
            'medications.audit.view',
            $siteId,
            $clientId,
        );
        $this->governanceScope->readerSiteIds(
            $user,
            'medications.audit.export',
            $siteId,
            $clientId,
        );
        $readerSiteIds = $siteId !== null ? [$siteId] : $siteIds;
        $access = app(MedicationReportAccess::class);
        $access->siteIds($user, $siteId, $clientId);

        $q = $this->baseQuery(
            $access->clientIds($user, $readerSiteIds),
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );
        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        $q->whereBetween('audit_logs.created_at', $period->bounds());
        // Read one row beyond the shared bound before preparing any file. A
        // large result must fail clearly rather than release a partial history.
        $logs = $q->limit(MedicationReportDataset::MAX_ROWS + 1)->get();
        abort_if($logs->count() > MedicationReportDataset::MAX_ROWS, 422, 'This change log has more than 100,000 rows. Choose a shorter period, house or person. No file has been made.');

        $filename = 'medications_audit_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Time (Pacific/Auckland)', 'Action', 'Type', 'ID', 'Client', 'User', 'Changed fields']);
            $logs->each(function ($l) use ($out) {
                $this->putCsv($out, [
                    $l->created_at?->timezone('Pacific/Auckland')->format('Y-m-d H:i:s T'),
                    $l->action,
                    class_basename($l->auditable_type),
                    $l->auditable_id,
                    $l->client ? trim($l->client->first_name.' '.$l->client->last_name) : '',
                    $l->user?->name ?? '',
                    implode(', ', $this->safeMeta($l->meta)['fields']),
                ]);
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function period(Request $request): MedicationReportPeriod
    {
        $request->validate(['client_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'], 'user_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        // Retained links use from/to; new readers and the export guard use the
        // canonical period fields. Both describe NZ dates, never UTC dates.
        if ($request->filled('from') || $request->filled('to')) {
            $request->merge(['period' => 'custom', 'date_from' => $request->input('from'), 'date_to' => $request->input('to')]);
        }

        return MedicationReportPeriod::fromRequest($request);
    }

    /** @return array{fields: array<int, string>} */
    private function safeMeta(mixed $meta): array
    {
        $fields = is_array($meta) ? ($meta['fields'] ?? []) : [];

        return [
            'fields' => collect(is_array($fields) ? $fields : [])
                ->filter(fn ($field) => is_string($field) && $field !== '')
                ->values()
                ->all(),
        ];
    }
}
