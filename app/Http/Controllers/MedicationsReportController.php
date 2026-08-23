<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedicationAdministration;
use App\Models\ServiceContext;
use Illuminate\Http\Request;

class MedicationsReportController extends Controller
{
    use SanitizesCsvOutput;

    public function index(Request $request)
    {
        // Access is permission-gated at the route level (reports.viewAny)
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'service_context_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:given,refused,missed,withheld'],
            'discrepancy_status' => ['nullable', 'in:open,under_review,closed'],
        ]);

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? \Carbon\Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(14)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? \Carbon\Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $admins = ClientMedicationAdministration::query()
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name,is_prn,controlled_drug,deleted_at',
                'administeredBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->whereBetween('administered_at', [$dateFrom, $dateTo]);

        if (!empty($filters['client_id'])) {
            $admins->where('client_id', (int) $filters['client_id']);
        }
        if (!empty($filters['service_context_id'])) {
            $admins->where('service_context_id', (int) $filters['service_context_id']);
        }
        if (!empty($filters['status'])) {
            $admins->where('status', $filters['status']);
        }

        $administrations = $admins
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(function (ClientMedicationAdministration $a) {
                return [
                    'id' => $a->id,
                    'administered_at' => $a->administered_at,
                    'scheduled_for' => $a->scheduled_for,
                    'status' => $a->status,
                    'reason' => $a->reason,
                    'dose_given' => $a->dose_given,
                    'notes' => $a->notes,
                    'client' => $a->client,
                    'medication' => $a->medication ? [
                        'id' => $a->medication->id,
                        'client_id' => $a->medication->client_id,
                        'name' => $a->medication->historicalDisplayName(),
                        'is_prn' => $a->medication->is_prn,
                        'controlled_drug' => $a->medication->controlled_drug,
                    ] : null,
                    'administeredBy' => $a->administeredBy,
                    'serviceContext' => $a->serviceContext ? [
                        'id' => $a->serviceContext->id,
                        'name' => $a->serviceContext->name,
                        'type' => (string) ($a->serviceContext->type?->value ?? $a->serviceContext->type),
                    ] : null,
                ];
            })
            ->values();

        $discQ = ClientControlledDrugDiscrepancy::query()
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name,controlled_drug',
                'reportedBy:id,name,email',
                'witnessedBy:id,name,email',
                'resolvedBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->whereBetween('reported_at', [$dateFrom, $dateTo]);

        if (!empty($filters['client_id'])) {
            $discQ->where('client_id', (int) $filters['client_id']);
        }
        if (!empty($filters['service_context_id'])) {
            $discQ->where('service_context_id', (int) $filters['service_context_id']);
        }
        if (!empty($filters['discrepancy_status'])) {
            $discQ->where('status', $filters['discrepancy_status']);
        }

        $discrepancies = $discQ
            ->orderByRaw("status = 'open' desc, status = 'under_review' desc")
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(function (ClientControlledDrugDiscrepancy $d) {
                return [
                    'id' => $d->id,
                    'status' => $d->status,
                    'difference' => $d->difference,
                    'on_hand_before' => $d->on_hand_before,
                    'on_hand_after' => $d->on_hand_after,
                    'reason' => $d->reason,
                    'notes' => $d->notes,
                    'reported_at' => $d->reported_at,
                    'resolved_at' => $d->resolved_at,
                    'resolution_notes' => $d->resolution_notes,
                    'client' => $d->client,
                    'medication' => $d->medication,
                    'reportedBy' => $d->reportedBy,
                    'witnessedBy' => $d->witnessedBy,
                    'resolvedBy' => $d->resolvedBy,
                    'serviceContext' => $d->serviceContext ? [
                        'id' => $d->serviceContext->id,
                        'name' => $d->serviceContext->name,
                        'type' => (string) ($d->serviceContext->type?->value ?? $d->serviceContext->type),
                    ] : null,
                ];
            })
            ->values();

        $clients = Client::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => trim("{$c->first_name} {$c->last_name}"),
            ])
            ->values();

        $serviceContexts = ServiceContext::query()
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (ServiceContext $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => (string) ($s->type?->value ?? $s->type),
            ])
            ->values();

        return inertia('reports/medications', [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'client_id' => $filters['client_id'] ?? null,
                'service_context_id' => $filters['service_context_id'] ?? null,
                'status' => $filters['status'] ?? null,
                'discrepancy_status' => $filters['discrepancy_status'] ?? null,
            ],
            'clients' => $clients,
            'service_contexts' => $serviceContexts,
            'administrations' => $administrations,
            'discrepancies' => $discrepancies,
        ]);
    }

    public function exportMarCsv(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'service_context_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:given,refused,missed,withheld'],
        ]);

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? \Carbon\Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(14)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? \Carbon\Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $q = ClientMedicationAdministration::query()
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name,is_prn,controlled_drug,deleted_at',
                'administeredBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->whereBetween('administered_at', [$dateFrom, $dateTo]);

        if (!empty($filters['client_id'])) {
            $q->where('client_id', (int) $filters['client_id']);
        }
        if (!empty($filters['service_context_id'])) {
            $q->where('service_context_id', (int) $filters['service_context_id']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        $filename = 'mar_export_' . now()->format('Ymd_His') . '.csv';

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
                    $clientName = trim(($a->client?->first_name ?? '') . ' ' . ($a->client?->last_name ?? ''));
                    $contextName = $a->serviceContext?->name ?? '';
                    $this->putCsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        optional($a->scheduled_for)->toDateTimeString(),
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
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'service_context_id' => ['nullable', 'integer'],
            'discrepancy_status' => ['nullable', 'in:open,under_review,closed'],
        ]);

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? \Carbon\Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(14)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? \Carbon\Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $q = ClientControlledDrugDiscrepancy::query()
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name,controlled_drug',
                'reportedBy:id,name,email',
                'witnessedBy:id,name,email',
                'resolvedBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->whereBetween('reported_at', [$dateFrom, $dateTo]);

        if (!empty($filters['client_id'])) {
            $q->where('client_id', (int) $filters['client_id']);
        }
        if (!empty($filters['service_context_id'])) {
            $q->where('service_context_id', (int) $filters['service_context_id']);
        }
        if (!empty($filters['discrepancy_status'])) {
            $q->where('status', $filters['discrepancy_status']);
        }

        $filename = 'controlled_discrepancies_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Reported At',
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
                'Resolved At',
                'Resolved By',
                'Resolution Notes',
                'Notes',
            ]);

            $q->orderBy('reported_at')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $d) {
                    $clientName = trim(($d->client?->first_name ?? '') . ' ' . ($d->client?->last_name ?? ''));
                    $this->putCsv($out, [
                        optional($d->reported_at)->toDateTimeString(),
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
                        optional($d->resolved_at)->toDateTimeString(),
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
}
