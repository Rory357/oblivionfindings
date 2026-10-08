<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read committed evidence after a lost response without attempting another dose. */
final class MedicationRecordingStatusController extends Controller
{
    public function __invoke(Request $request, MedicationRecordAccess $access, MarScheduleService $schedule): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.view'), 404);
        $data = $request->validate([
            'client_medication_id' => ['required', 'integer', 'min:1'],
            'client_request_uuid' => ['required', 'uuid'],
        ]);
        // A replaced or archived order still owns its committed receipt. Recheck
        // the current person and Site authority instead of requiring an active order.
        $medicine = ClientMedication::withTrashed()->findOrFail((int) $data['client_medication_id']);
        $client = $access->client($actor, (int) $medicine->client_id);
        abort_if($medicine->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);

        // Filtering the actor before reading the receipt gives a foreign UUID
        // exactly the same answer as an unknown one. Neither proves failure.
        $records = ClientMedicationAdministration::query()
            ->where('client_id', $medicine->client_id)
            ->where('client_medication_id', $medicine->id)
            ->where('client_request_uuid', $data['client_request_uuid'])
            ->where('administered_by', $actor->id)
            ->limit(2)->get(['id', 'scheduled_for', 'administered_at']);
        abort_if($records->count() > 1, 404);
        $record = $records->first();
        $at = $record?->scheduled_for ?? $record?->administered_at;
        $payload = ['status' => 'unconfirmed'];
        if ($record !== null && $at !== null) {
            $payload = ['status' => 'recorded', 'administration_id' => (int) $record->id,
                'chart_url' => route('emar.mar', [
                    'client_id' => (int) $medicine->client_id, 'site_id' => $client->site_id,
                    'date' => $at->copy()->timezone($schedule->workerTimezone())->toDateString(),
                    'tab' => 'history', 'dose_id' => (int) $record->id,
                ], false)];
        }

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
