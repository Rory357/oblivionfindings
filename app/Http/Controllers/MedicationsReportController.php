<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedicationAdministration;
use App\Models\ServiceContext;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MedicationsReportController extends Controller
{
    use SanitizesCsvOutput;

    public function __construct(private readonly MedicationGovernanceScopeService $governanceScope) {}
    public function exportMarCsv(Request $request)
    {
        $canViewControlled = $request->user()?->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY) ?? false;
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'service_context_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:given,refused,missed,withheld'],
            'include_prn' => ['nullable', 'boolean'],
        ]);

        // NZ days, both ends included (a date is the organisation's day,
        // never a UTC midnight that drops the morning).
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? Carbon::parse($filters['date_from'], $timezone)->startOfDay()->utc()
            : Carbon::now($timezone)->subDays(14)->startOfDay()->utc();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? Carbon::parse($filters['date_to'], $timezone)->endOfDay()->utc()
            : Carbon::now($timezone)->endOfDay()->utc();
        [, $readerSiteIds] = $this->readerSiteIds($request, $filters);
        if (! empty($filters['client_id'])) {
            // The report scope limits the house; the person rule follows (P02).
            app(MedicationRecordAccess::class)->assertReportable(
                $request->user(),
                Client::query()->find((int) $filters['client_id']),
            );
        }

        $q = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        )
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name,is_prn,controlled_drug,deleted_at',
                'administeredBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->whereBetween('administered_at', [$dateFrom, $dateTo]);
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($q);
        }

        if (! empty($filters['client_id'])) {
            $q->where('client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['service_context_id'])) {
            $q->where('service_context_id', (int) $filters['service_context_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (array_key_exists('include_prn', $filters) && ! $request->boolean('include_prn')) {
            $q->whereHas('medication', fn ($medication) => $medication->where('is_prn', false));
        }

        $filename = 'mar_export_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Administered At',
                'Scheduled For',
                'Client',
                'Medication',
                'Status',
                'Reason',
                'Dose Given',
                'Administered By',
                'Service Context',
                'Notes',
            ]);

            $q->orderBy('administered_at')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '').' '.($a->client?->last_name ?? ''));
                    $contextName = $a->serviceContext?->name ?? '';
                    $this->putCsv($out, [
                        // EA-175 (Lane C7): NZ time with its zone, like the NZ-day filter.
                        $a->administered_at ? \App\Support\WorkerClock::display($a->administered_at, 'Y-m-d H:i:s T') : '',
                        $a->scheduled_for ? \App\Support\WorkerClock::display($a->scheduled_for, 'Y-m-d H:i:s T') : '',
                        $clientName,
                        $a->medication?->historicalDisplayName() ?? '',
                        $a->status,
                        $a->reason,
                        $a->dose_given,
                        $a->administeredBy?->name ?? '',
                        $contextName,
                        $a->notes,
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportDiscrepanciesCsv(Request $request)
    {
        abort_unless($request->user()?->canDo('medications.controlled.view'), 403);

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'service_context_id' => ['nullable', 'integer'],
            'discrepancy_status' => ['nullable', 'in:open,under_review,closed'],
        ]);

        [$dateFrom, $dateTo] = MedicationReportPeriod::fromRequest($request)->bounds();
        [, $readerSiteIds] = $this->readerSiteIds($request, $filters, true);

        $q = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientControlledDrugDiscrepancy::query(),
            $readerSiteIds,
            false,
        )
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name,controlled_drug',
                'reportedBy:id,name,email',
                'witnessedBy:id,name,email',
                'resolvedBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->whereBetween('reported_at', [$dateFrom, $dateTo]);

        if (! empty($filters['client_id'])) {
            $q->where('client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['service_context_id'])) {
            $q->where('service_context_id', (int) $filters['service_context_id']);
        }
        if (! empty($filters['discrepancy_status'])) {
            $q->where('status', $filters['discrepancy_status']);
        }

        $filename = 'controlled_discrepancies_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Reported At (Pacific/Auckland)',
                'Status',
                'Client',
                'Medication',
                'On Hand Before',
                'On Hand After',
                'Difference',
                'Reason',
                'Reported By',
                'Witnessed By',
                'Service Context',
                'Resolved At (Pacific/Auckland)',
                'Resolved By',
                'Resolution Notes',
                'Notes',
            ]);

            $q->orderBy('reported_at')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $d) {
                    $clientName = trim(($d->client?->first_name ?? '').' '.($d->client?->last_name ?? ''));
                    $this->putCsv($out, [
                        $d->reported_at?->timezone('Pacific/Auckland')->format('Y-m-d H:i:s T'),
                        $d->status,
                        $clientName,
                        $d->medication?->name ?? '',
                        $d->on_hand_before,
                        $d->on_hand_after,
                        $d->difference,
                        $d->reason,
                        $d->reportedBy?->name ?? '',
                        $d->witnessedBy?->name ?? '',
                        $d->serviceContext?->name ?? '',
                        $d->resolved_at?->timezone('Pacific/Auckland')->format('Y-m-d H:i:s T'),
                        $d->resolvedBy?->name ?? '',
                        $d->resolution_notes,
                        $d->notes,
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function readerSiteIds(
        Request $request,
        array $filters,
        bool $controlled = false,
    ): array {
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        $siteId = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $accessibleSiteIds = $this->governanceScope->reportSiteIds($actor);
        $readerSiteIds = $this->governanceScope->reportSiteIds(
            $actor,
            $siteId,
            $clientId,
            $controlled,
        );

        return [$accessibleSiteIds, $readerSiteIds];
    }
}
