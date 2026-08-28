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
        $clientIds = $this->clientIds($readerSiteIds);

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
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->query('to'));
        }

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

        return inertia('medications/audit', [
            'filters' => [
                'client_id' => $request->query('client_id'),
                'site_id' => $request->query('site_id'),
                'user_id' => $request->query('user_id'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
            'logs' => $logs,
            'clients' => $this->governanceScope->clientPicker($readerSiteIds),
            'sites' => $this->governanceScope->sitePicker($siteIds),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
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
            'medications.reports.export',
            $siteId,
            $clientId,
        );
        $readerSiteIds = $siteId !== null ? [$siteId] : $siteIds;

        $q = $this->baseQuery(
            $this->clientIds($readerSiteIds),
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );
        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->query('to'));
        }

        $filename = 'medications_audit_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Time', 'Action', 'Type', 'ID', 'Client', 'User', 'Changed fields']);
            $q->limit(5000)->get()->each(function ($l) use ($out) {
                $this->putCsv($out, [
                    optional($l->created_at)->toDateTimeString(),
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

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, int>
     */
    private function clientIds(array $siteIds): array
    {
        return Client::query()
            ->whereIn('site_id', $siteIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
