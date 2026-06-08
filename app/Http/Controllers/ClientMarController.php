<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\EnhancedMarService;
use App\Services\MarScheduleService;
use App\Services\MedicationAlertService;
use App\Support\EmarUrl;
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
            $canonicalMarUrl = EmarUrl::mar($client, $request->query('date', now()->toDateString()));

            return inertia('emergency/request', [
                'client' => $client->only(['id', 'first_name', 'last_name']),
                'redirectTo' => url($canonicalMarUrl),
            ]);
        }

        abort_unless($user && ($user->canDo('medications.view') || $user->canDo('medications.administer.record') || $user->canDo('clients.update')), 403);

        $date = app(MarScheduleService::class)->dateFromInput($request->query('date'));
        return redirect()->to(EmarUrl::mar($client, $date->startOfDay()->toDateString()));
    }

    public function exportCsv(Request $request, Client $client): StreamedResponse
    {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.reports.export') || $user->canDo('reports.viewAny') || $user->canDo('clients.update')), 403);

        $date = app(MarScheduleService::class)->dateFromInput($request->query('date'));
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
