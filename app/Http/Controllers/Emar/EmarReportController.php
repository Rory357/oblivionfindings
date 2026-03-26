<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationRound;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmarReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'report_type' => ['nullable', 'string'],
        ]);

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $clientId = $filters['client_id'] ?? null;

        // ─── Administration Summary ──────────────────────────
        $adminQuery = ClientMedicationAdministration::query()
            ->whereBetween('administered_at', [$dateFrom, $dateTo]);
        if ($clientId) {
            $adminQuery->where('client_id', $clientId);
        }

        $adminTotals = (clone $adminQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given_count,
            SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused_count,
            SUM(CASE WHEN status = 'withheld' THEN 1 ELSE 0 END) as withheld_count,
            SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed_count
        ")->first();

        $adminSummary = [
            'total' => (int) ($adminTotals->total ?? 0),
            'given' => (int) ($adminTotals->given_count ?? 0),
            'refused' => (int) ($adminTotals->refused_count ?? 0),
            'withheld' => (int) ($adminTotals->withheld_count ?? 0),
            'missed' => (int) ($adminTotals->missed_count ?? 0),
            'compliance_rate' => $adminTotals->total > 0
                ? round(($adminTotals->given_count / $adminTotals->total) * 100, 1)
                : 0,
        ];

        // Daily breakdown for chart
        $dailyAdmin = (clone $adminQuery)
            ->selectRaw("
                DATE(administered_at) as date,
                SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given_count,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused_count,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed_count,
                COUNT(*) as total
            ")
            ->groupByRaw('DATE(administered_at)')
            ->orderByRaw('DATE(administered_at)')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->format('M d'),
                'given' => (int) $row->given_count,
                'refused' => (int) $row->refused_count,
                'missed' => (int) $row->missed_count,
                'total' => (int) $row->total,
            ])
            ->values();

        // Breakdown by client
        $clientBreakdown = (clone $adminQuery)
            ->join('clients', 'clients.id', '=', 'client_medication_administrations.client_id')
            ->selectRaw("
                client_medication_administrations.client_id,
                CONCAT(clients.first_name, ' ', clients.last_name) as client_name,
                COUNT(*) as total,
                SUM(CASE WHEN client_medication_administrations.status = 'given' THEN 1 ELSE 0 END) as given_count,
                SUM(CASE WHEN client_medication_administrations.status = 'refused' THEN 1 ELSE 0 END) as refused_count,
                SUM(CASE WHEN client_medication_administrations.status = 'withheld' THEN 1 ELSE 0 END) as withheld_count,
                SUM(CASE WHEN client_medication_administrations.status = 'missed' THEN 1 ELSE 0 END) as missed_count
            ")
            ->groupBy('client_medication_administrations.client_id', 'clients.first_name', 'clients.last_name')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                'client_name' => $row->client_name,
                'total' => (int) $row->total,
                'given' => (int) $row->given_count,
                'refused' => (int) $row->refused_count,
                'withheld' => (int) $row->withheld_count,
                'missed' => (int) $row->missed_count,
                'compliance' => $row->total > 0 ? round(($row->given_count / $row->total) * 100, 1) : 0,
            ])
            ->values();

        // ─── PRN Usage ──────────────────────────────────────
        $prnQuery = ClientMedicationAdministration::query()
            ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
            ->where('client_medications.is_prn', true)
            ->whereBetween('client_medication_administrations.administered_at', [$dateFrom, $dateTo]);
        if ($clientId) {
            $prnQuery->where('client_medication_administrations.client_id', $clientId);
        }

        $topPrnMeds = (clone $prnQuery)
            ->selectRaw('client_medications.name as medication_name, COUNT(*) as usage_count')
            ->groupBy('client_medications.name')
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'medication' => $row->medication_name,
                'count' => (int) $row->usage_count,
            ])
            ->values();

        $dayCount = max(1, $dateFrom->diffInDays($dateTo));

        $prnByClient = (clone $prnQuery)
            ->join('clients', 'clients.id', '=', 'client_medication_administrations.client_id')
            ->selectRaw("
                client_medication_administrations.client_id,
                CONCAT(clients.first_name, ' ', clients.last_name) as client_name,
                client_medications.name as medication_name,
                COUNT(*) as usage_count
            ")
            ->groupBy('client_medication_administrations.client_id', 'clients.first_name', 'clients.last_name', 'client_medications.name')
            ->orderByDesc('usage_count')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'client_name' => $row->client_name,
                'medication' => $row->medication_name,
                'count' => (int) $row->usage_count,
                'avg_per_day' => round($row->usage_count / $dayCount, 1),
            ])
            ->values();

        // ─── Controlled Drugs ────────────────────────────────
        $cdAdminCount = ClientMedicationAdministration::query()
            ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
            ->where('client_medications.controlled_drug', true)
            ->whereBetween('client_medication_administrations.administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_medication_administrations.client_id', $clientId))
            ->count();

        $destructionsCount = MedicationDestruction::query()
            ->where('is_controlled_drug', true)
            ->whereBetween('destroyed_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->count();

        $discrepancyQuery = ClientControlledDrugDiscrepancy::query()
            ->whereBetween('reported_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId));

        $discrepancyCount = (clone $discrepancyQuery)->count();

        $cdByMedication = ClientMedicationAdministration::query()
            ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
            ->where('client_medications.controlled_drug', true)
            ->whereBetween('client_medication_administrations.administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_medication_administrations.client_id', $clientId))
            ->selectRaw('client_medications.name as medication_name, COUNT(*) as admin_count')
            ->groupBy('client_medications.name')
            ->orderByDesc('admin_count')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'medication' => $row->medication_name,
                'administrations' => (int) $row->admin_count,
            ])
            ->values();

        // ─── Staff Compliance ────────────────────────────────
        $today = Carbon::today();

        $currentCompetency = MedicationCompetencyAssessment::where('status', 'passed')
            ->where('expiry_date', '>', $today)
            ->where('expiry_date', '>', $today->copy()->addDays(30))
            ->count();

        $expiringCompetency = MedicationCompetencyAssessment::where('status', 'passed')
            ->where('expiry_date', '>', $today)
            ->where('expiry_date', '<=', $today->copy()->addDays(30))
            ->count();

        $expiredCompetency = MedicationCompetencyAssessment::where('status', 'passed')
            ->where('expiry_date', '<=', $today)
            ->count();

        $staffCompetencyList = MedicationCompetencyAssessment::query()
            ->with('user:id,name')
            ->whereIn('status', ['passed', 'failed'])
            ->orderByDesc('assessment_date')
            ->limit(50)
            ->get()
            ->map(function ($a) use ($today) {
                $daysUntilExpiry = $a->expiry_date ? $today->diffInDays($a->expiry_date, false) : null;
                $status = 'current';
                if ($a->status === 'failed') {
                    $status = 'failed';
                } elseif ($a->expiry_date && $a->expiry_date->lte($today)) {
                    $status = 'expired';
                } elseif ($daysUntilExpiry !== null && $daysUntilExpiry <= 30) {
                    $status = 'expiring';
                }

                return [
                    'staff_name' => $a->user?->name ?? 'Unknown',
                    'assessment_date' => $a->assessment_date?->toDateString(),
                    'expiry_date' => $a->expiry_date?->toDateString(),
                    'status' => $status,
                    'days_until_expiry' => $daysUntilExpiry !== null ? (int) $daysUntilExpiry : null,
                ];
            })
            ->values();

        // ─── Stock Status ────────────────────────────────────
        $totalStockItems = ClientMedicationStock::count();

        $lowStockCount = ClientMedicationStock::whereColumn('on_hand', '<=', 'reorder_level')->count();

        $expiringStockCount = ClientMedicationStock::whereNotNull('expiry_date')
            ->where('expiry_date', '>', $today)
            ->where('expiry_date', '<=', $today->copy()->addDays(30))
            ->count();

        $expiredStockCount = ClientMedicationStock::whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $today)
            ->count();

        $activeMediactions = ClientMedication::where('active', true)->count();

        $stockList = ClientMedicationStock::query()
            ->with(['medication:id,client_id,name', 'medication.client:id,first_name,last_name'])
            ->orderByRaw('CASE WHEN on_hand <= reorder_level THEN 0 ELSE 1 END')
            ->orderBy('expiry_date')
            ->limit(50)
            ->get()
            ->map(function ($s) use ($today) {
                $status = 'ok';
                if ($s->expiry_date && $s->expiry_date->lte($today)) {
                    $status = 'expired';
                } elseif ($s->expiry_date && $s->expiry_date->lte($today->copy()->addDays(30))) {
                    $status = 'expiring';
                } elseif ($s->on_hand <= $s->reorder_level) {
                    $status = 'low';
                }

                $client = $s->medication?->client;
                $clientName = $client ? trim("{$client->first_name} {$client->last_name}") : '';

                return [
                    'medication' => $s->medication?->name ?? 'Unknown',
                    'client' => $clientName,
                    'on_hand' => (int) $s->on_hand,
                    'reorder_level' => (int) $s->reorder_level,
                    'expiry_date' => $s->expiry_date?->toDateString(),
                    'status' => $status,
                ];
            })
            ->values();

        // ─── Round Completion ────────────────────────────────
        $roundQuery = MedicationRound::query()
            ->whereBetween('round_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        $roundTotals = (clone $roundQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'completed' AND completed_at <= DATE_ADD(CONCAT(round_date, ' ', scheduled_time), INTERVAL window_minutes MINUTE) THEN 1 ELSE 0 END) as on_time,
            SUM(CASE WHEN status = 'completed' AND completed_at > DATE_ADD(CONCAT(round_date, ' ', scheduled_time), INTERVAL window_minutes MINUTE) THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed
        ")->first();

        $totalRounds = (int) ($roundTotals->total ?? 0);
        $completedRounds = (int) ($roundTotals->completed ?? 0);
        $onTimeRounds = (int) ($roundTotals->on_time ?? 0);
        $lateRounds = (int) ($roundTotals->late ?? 0);
        $missedRounds = (int) ($roundTotals->missed ?? 0);

        $roundSummary = [
            'total' => $totalRounds,
            'completed' => $completedRounds,
            'on_time_pct' => $totalRounds > 0 ? round(($onTimeRounds / $totalRounds) * 100, 1) : 0,
            'late_pct' => $totalRounds > 0 ? round(($lateRounds / $totalRounds) * 100, 1) : 0,
            'missed_pct' => $totalRounds > 0 ? round(($missedRounds / $totalRounds) * 100, 1) : 0,
        ];

        $dailyRounds = (clone $roundQuery)
            ->selectRaw("
                round_date as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed
            ")
            ->groupBy('round_date')
            ->orderBy('round_date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->format('M d'),
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'missed' => (int) $row->missed,
            ])
            ->values();

        // ─── Error Summary ───────────────────────────────────
        $errorQuery = MedicationError::query()
            ->whereBetween('reported_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId));

        $errorTotals = (clone $errorQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
        ")->first();

        $errorsByType = (clone $errorQuery)
            ->selectRaw('error_type, COUNT(*) as count')
            ->groupBy('error_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->error_type,
                'count' => (int) $row->count,
            ])
            ->values();

        $errorList = (clone $errorQuery)
            ->with(['client:id,first_name,last_name'])
            ->orderByDesc('reported_at')
            ->limit(50)
            ->get()
            ->map(fn ($e) => [
                'date' => $e->reported_at?->toDateString(),
                'client' => $e->client ? trim("{$e->client->first_name} {$e->client->last_name}") : '',
                'type' => $e->error_type,
                'severity' => $e->severity,
                'status' => $e->status,
            ])
            ->values();

        // ─── Client list for filter ──────────────────────────
        $clients = Client::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => trim("{$c->first_name} {$c->last_name}"),
            ])
            ->values();

        return Inertia::render('emar/Reports', [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'client_id' => $clientId ? (int) $clientId : null,
                'report_type' => $filters['report_type'] ?? null,
            ],
            'clients' => $clients,
            'adminSummary' => $adminSummary,
            'dailyAdmin' => $dailyAdmin,
            'clientBreakdown' => $clientBreakdown,
            'topPrnMeds' => $topPrnMeds,
            'prnByClient' => $prnByClient,
            'controlledDrugs' => [
                'administrations' => $cdAdminCount,
                'destructions' => $destructionsCount,
                'discrepancies' => $discrepancyCount,
                'byMedication' => $cdByMedication,
            ],
            'staffCompliance' => [
                'current' => $currentCompetency,
                'expiring' => $expiringCompetency,
                'expired' => $expiredCompetency,
                'list' => $staffCompetencyList,
            ],
            'stockStatus' => [
                'total' => $totalStockItems,
                'low' => $lowStockCount,
                'expiring' => $expiringStockCount,
                'expired' => $expiredStockCount,
                'active_medications' => $activeMediactions,
                'list' => $stockList,
            ],
            'roundCompletion' => [
                'summary' => $roundSummary,
                'daily' => $dailyRounds,
            ],
            'errorSummary' => [
                'total' => (int) ($errorTotals->total ?? 0),
                'critical' => (int) ($errorTotals->critical ?? 0),
                'open' => (int) ($errorTotals->open_count ?? 0),
                'resolved' => (int) ($errorTotals->resolved ?? 0),
                'byType' => $errorsByType,
                'list' => $errorList,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'report_type' => ['nullable', 'string', 'in:administration,prn,controlled,rounds,errors'],
        ]);

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $clientId = $filters['client_id'] ?? null;
        $type = $filters['report_type'] ?? 'administration';

        $filename = "emar_{$type}_report_" . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($type, $dateFrom, $dateTo, $clientId) {
            $out = fopen('php://output', 'w');

            match ($type) {
                'administration' => $this->exportAdministrations($out, $dateFrom, $dateTo, $clientId),
                'prn' => $this->exportPrn($out, $dateFrom, $dateTo, $clientId),
                'controlled' => $this->exportControlled($out, $dateFrom, $dateTo, $clientId),
                'rounds' => $this->exportRounds($out, $dateFrom, $dateTo),
                'errors' => $this->exportErrors($out, $dateFrom, $dateTo, $clientId),
                default => $this->exportAdministrations($out, $dateFrom, $dateTo, $clientId),
            };

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportAdministrations($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId): void
    {
        fputcsv($out, ['Date', 'Client', 'Medication', 'Status', 'Administered By', 'Notes']);

        ClientMedicationAdministration::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'administeredBy:id,name'])
            ->whereBetween('administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('administered_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '') . ' ' . ($a->client?->last_name ?? ''));
                    fputcsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        $clientName,
                        $a->medication?->name ?? '',
                        $a->status,
                        $a->administeredBy?->name ?? '',
                        $a->notes,
                    ]);
                }
            });
    }

    private function exportPrn($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId): void
    {
        fputcsv($out, ['Date', 'Client', 'Medication', 'Dose', 'Reason', 'Administered By']);

        ClientMedicationAdministration::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name,is_prn', 'administeredBy:id,name'])
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->whereBetween('administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('administered_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '') . ' ' . ($a->client?->last_name ?? ''));
                    fputcsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        $clientName,
                        $a->medication?->name ?? '',
                        $a->dose_given,
                        $a->reason,
                        $a->administeredBy?->name ?? '',
                    ]);
                }
            });
    }

    private function exportControlled($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId): void
    {
        fputcsv($out, ['Date', 'Client', 'Medication', 'Status', 'Dose', 'Administered By', 'Witness']);

        ClientMedicationAdministration::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name,controlled_drug', 'administeredBy:id,name'])
            ->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))
            ->whereBetween('administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('administered_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '') . ' ' . ($a->client?->last_name ?? ''));
                    fputcsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        $clientName,
                        $a->medication?->name ?? '',
                        $a->status,
                        $a->dose_given,
                        $a->administeredBy?->name ?? '',
                        '',
                    ]);
                }
            });
    }

    private function exportRounds($out, Carbon $dateFrom, Carbon $dateTo): void
    {
        fputcsv($out, ['Date', 'Name', 'Status', 'Scheduled Time', 'Started At', 'Completed At', 'Total Meds', 'Administered', 'Refused', 'Missed']);

        MedicationRound::query()
            ->whereBetween('round_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('round_date')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->round_date?->toDateString(),
                        $r->name,
                        $r->status,
                        $r->scheduled_time,
                        optional($r->started_at)->toDateTimeString(),
                        optional($r->completed_at)->toDateTimeString(),
                        $r->total_medications,
                        $r->administered_count,
                        $r->refused_count,
                        $r->missed_count,
                    ]);
                }
            });
    }

    private function exportErrors($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId): void
    {
        fputcsv($out, ['Date', 'Client', 'Type', 'Severity', 'Status', 'Description']);

        MedicationError::query()
            ->with(['client:id,first_name,last_name'])
            ->whereBetween('reported_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('reported_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $e) {
                    $clientName = trim(($e->client?->first_name ?? '') . ' ' . ($e->client?->last_name ?? ''));
                    fputcsv($out, [
                        optional($e->reported_at)->toDateTimeString(),
                        $clientName,
                        $e->error_type,
                        $e->severity,
                        $e->status,
                        $e->description,
                    ]);
                }
            });
    }
}
