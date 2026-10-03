<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
use App\Models\Client;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationIdempotencyResult;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PersonMedicationCorrectionController extends Controller
{
    public function store(Request $request, int $client, int $administration, string $command)
    {
        abort_unless(in_array($command, ['request', 'approve', 'reject'], true), 404);
        $actor = $request->user();
        app(MedicationRecordAccess::class)->client($actor, $client);
        $valid = $request->validate(['request_uuid' => ['required', 'uuid'], 'correction_reason' => [$command === 'request' ? 'required' : 'nullable', 'string', 'max:255']]);
        $result = DB::transaction(fn () => app(MedicationGovernanceScopeService::class)->forClient($actor, $client, 'medications.administer.correct', function (Client $person, User $lockedActor) use ($request, $administration, $command, $valid) {
            app(MedicationRecordAccess::class)->assertReadable($lockedActor, $person);
            $scope = 'p02-correction:'.$person->id.':'.$lockedActor->id.':'.$command;
            $fingerprint = hash('sha256', json_encode([$administration, $request->except('_token')], JSON_THROW_ON_ERROR));
            $receipt = MedicationIdempotencyResult::query()->where('scope', $scope)->where('request_uuid', $valid['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->response_payload['fingerprint'], $fingerprint), 409);

                return $receipt->response_payload['result'];
            }
            $record = ClientMedicationAdministration::query()->where('client_id', $person->id)->findOrFail($administration);
            $handler = app(MedicationAdministrationCorrectionController::class);
            $events = [];
            match ($command) {
                'request' => $handler->store($request, $person, $record, $events),
                'approve' => $handler->approve($request, $record, $events),
                'reject' => $handler->reject($request, $record, $events),
            };
            $result = ['saved' => true];
            MedicationIdempotencyResult::query()->create(['scope' => $scope, 'request_uuid' => $valid['request_uuid'], 'response_payload' => ['fingerprint' => $fingerprint, 'result' => $result], 'expires_at' => now()->addDays(30)]);
            app(MedicationEventRecorder::class)->appendMany($events);

            return $result;
        }), 5);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
