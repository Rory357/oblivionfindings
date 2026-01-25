<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\MedicationMarService;
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

        $payload = app(MedicationMarService::class)->build($client, $date);

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

        $openControlledDiscrepancies = \App\Models\ClientControlledDrugDiscrepancy::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['open', 'under_review'])
            ->exists();

        return inertia('clients/mar', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'date' => $date->toDateString(),
            'rows' => $payload['rows'],
            'history' => $payload['history'],
            'break_glass' => $activeBreakGlass,
            'has_open_controlled_discrepancy' => $openControlledDiscrepancies,
            'can' => [
                'record' => (bool) ($user->canDo('medications.administer.record') || $user->canDo('clients.update')),
                'correct' => (bool) ($user->canDo('medications.administer.correct') || $user->canDo('clients.update')),
                'export' => (bool) ($user->canDo('medications.reports.export') || $user->canDo('reports.viewAny') || $user->canDo('clients.update')),
                'break_glass' => (bool) $user->canDo('medications.breakglass'),
            ],
        ]);
    }

    public function exportCsv(Request $request, Client $client): StreamedResponse
    {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.reports.export') || $user->canDo('reports.viewAny') || $user->canDo('clients.update')), 403);

        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();
        $date = $date->startOfDay();
        $payload = app(MedicationMarService::class)->build($client, $date);

        $filename = 'MAR_' . $client->id . '_' . $date->toDateString() . '.csv';
        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Scheduled', 'Medication', 'Dosage', 'Route', 'Form', 'Status', 'Administered at', 'Reason', 'Notes']);
            foreach ($payload['rows'] as $row) {
                $rec = $row['record'];
                fputcsv($out, [
                    $row['scheduled_for'] ? Carbon::parse($row['scheduled_for'])->format('Y-m-d H:i') : 'PRN',
                    $row['medication']['name'] ?? '',
                    $row['medication']['dosage'] ?? '',
                    $row['medication']['route'] ?? '',
                    $row['medication']['form'] ?? '',
                    $rec['status'] ?? '',
                    $rec['administered_at'] ? Carbon::parse($rec['administered_at'])->format('Y-m-d H:i') : '',
                    $rec['reason'] ?? '',
                    $rec['notes'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
