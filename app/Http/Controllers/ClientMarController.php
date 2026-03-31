<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\EnhancedMarService;
use App\Services\MedicationAlertService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientMarController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $user = $request->user();
        // If the user isn't allowed, but can break-glass, show the request screen instead of a hard 403.
        if (!$user?->can('viewMedications', $client)) {
            abort_unless($user && $user->canDo('medications.breakglass'), 403);
            return inertia('emergency/request', [
                'client' => $client->only(['id', 'first_name', 'last_name']),
                'redirectTo' => url("/clients/{$client->id}/mar"),
            ]);
        }

        abort_unless($user && ($user->canDo('medications.view') || $user->canDo('medications.administer.record') || $user->canDo('clients.update')), 403);

        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();
        $date = $date->startOfDay();

        $payload = app(EnhancedMarService::class)->build($client, $date);

        // Transform enhanced format to legacy format for compatibility with existing view
        $rows = [];
        foreach ($payload['scheduled'] as $scheduled) {
            $rows[] = [
                'medication' => [
                    'id' => $scheduled['client_medication_id'],
                    'name' => $scheduled['medication']['name'],
                    'dosage' => $scheduled['medication']['dosage'],
                    'route' => $scheduled['medication']['route'],
                    'form' => $scheduled['medication']['form'],
                    'is_prn' => false,
                    'prn_reason' => null,
                    'controlled_drug' => $scheduled['medication']['controlled_drug'],
                    'state' => $scheduled['medication']['state'],
                ],
                'scheduled_for' => $scheduled['scheduled_for'],
                'scheduled_time' => $scheduled['scheduled_time'],
                'schedule_state' => $scheduled['schedule_state'],
                'record' => $scheduled['administration'] ? [
                    'id' => $scheduled['administration']['id'],
                    'status' => $scheduled['administration']['status'],
                    'reason' => $scheduled['administration']['reason'],
                    'notes' => $scheduled['administration']['notes'],
                    'dose_given' => $scheduled['administration']['dose_given'],
                    'administered_at' => $scheduled['administration']['administered_at'],
                    'administered_by' => $scheduled['administration']['administered_by'] ? [
                        'name' => $scheduled['administration']['administered_by'],
                    ] : null,
                    'is_correction' => $scheduled['administration']['is_correction'] ?? false,
                    'correction_reason' => $scheduled['administration']['correction_reason'] ?? null,
                ] : null,
            ];
        }
        foreach ($payload['prn'] as $prn) {
            $rows[] = [
                'medication' => [
                    'id' => $prn['client_medication_id'],
                    'name' => $prn['medication']['name'],
                    'dosage' => $prn['medication']['dosage'],
                    'route' => $prn['medication']['route'],
                    'form' => $prn['medication']['form'],
                    'is_prn' => true,
                    'prn_reason' => $prn['prn_reason'],
                    'controlled_drug' => $prn['medication']['controlled_drug'],
                    'state' => $prn['medication']['state'],
                ],
                'scheduled_for' => null,
                'scheduled_time' => 'PRN',
                'schedule_state' => 'prn',
                'record' => null,
            ];
        }

        $activeBreakGlass = null;
        if ($user->canDo('medications.breakglass')) {
            $a = $client->breakGlassAccesses()
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->first();
            if ($a) {
                $activeBreakGlass = [
                    'id' => $a->id,
                    'reason' => $a->reason,
                    'expires_at' => $a->expires_at?->toIso8601String(),
                ];
            }
        }

        // Get controlled drug discrepancies with full details
        $controlledDiscrepancies = \App\Models\ClientControlledDrugDiscrepancy::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['open', 'under_review'])
            ->with(['medication', 'reportedBy'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'medication_name' => $d->medication?->name ?? 'Unknown',
                'expected_balance' => $d->on_hand_before,
                'actual_balance' => $d->on_hand_after,
                'discrepancy_amount' => $d->difference,
                'status' => $d->status,
                'reported_by' => $d->reportedBy?->name ?? 'System',
                'created_at' => $d->reported_at?->toIso8601String() ?? $d->created_at?->toIso8601String(),
            ]);

        // Get active medication alerts
        $alertService = app(MedicationAlertService::class);
        $activeAlerts = $alertService->getActiveAlertsForClient($client->id);

        // Witness pick-list (for controlled drug administrations)
        $witnesses = User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->filter(fn (User $u) => $u->canDo('medications.controlled.witness'))
            ->values()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        return inertia('operations/clients/mar', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'date' => $date->toDateString(),
            'rows' => $rows,
            'history' => $payload['history'],
            'break_glass' => $activeBreakGlass,
            'has_open_controlled_discrepancy' => $controlledDiscrepancies->isNotEmpty(),
            'controlled_discrepancies' => $controlledDiscrepancies,
            'alerts' => $activeAlerts,
            'witnesses' => $witnesses,
            'can' => [
                'record' => (bool) ($user->canDo('medications.administer.record') || $user->canDo('clients.update')),
                'correct' => (bool) ($user->canDo('medications.administer.correct') || $user->canDo('clients.update')),
                'export' => (bool) ($user->canDo('medications.reports.export') || $user->canDo('reports.viewAny') || $user->canDo('clients.update')),
                'break_glass' => (bool) $user->canDo('medications.breakglass'),
            ],
            'witnesses' => $witnesses,
        ]);
    }

    public function exportCsv(Request $request, Client $client): StreamedResponse
    {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.reports.export') || $user->canDo('reports.viewAny') || $user->canDo('clients.update')), 403);

        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();
        $date = $date->startOfDay();
        $payload = app(EnhancedMarService::class)->build($client, $date);

        $filename = 'MAR_' . $client->id . '_' . $date->toDateString() . '.csv';
        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Scheduled', 'Medication', 'Dosage', 'Route', 'Form', 'Status', 'Administered at', 'Reason', 'Notes']);
            // Export scheduled medications
            foreach ($payload['scheduled'] as $row) {
                $admin = $row['administration'];
                fputcsv($out, [
                    $row['scheduled_for'] ? Carbon::parse($row['scheduled_for'])->format('Y-m-d H:i') : '',
                    $row['medication']['name'] ?? '',
                    $row['medication']['dosage'] ?? '',
                    $row['medication']['route'] ?? '',
                    $row['medication']['form'] ?? '',
                    $admin['status'] ?? 'not_recorded',
                    $admin['administered_at'] ? Carbon::parse($admin['administered_at'])->format('Y-m-d H:i') : '',
                    $admin['reason'] ?? '',
                    $admin['notes'] ?? '',
                ]);
            }
            // Export PRN medications (as separate rows)
            foreach ($payload['prn'] as $prn) {
                fputcsv($out, [
                    'PRN',
                    $prn['medication']['name'] ?? '',
                    $prn['medication']['dosage'] ?? '',
                    $prn['medication']['route'] ?? '',
                    $prn['medication']['form'] ?? '',
                    'prn',
                    '',
                    $prn['prn_reason'] ?? '',
                    '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
