<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationSyringeDriver;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** P02 commands keep the existing driver policy and append the event last. */
final class PersonMedicationClinicalController extends Controller
{
    public function store(Request $request, int $client, string $command)
    {
        abort_unless(in_array($command, ['inr', 'link-inr', 'disable-inr', 'start-driver', 'check-driver', 'finish-driver'], true), 404);
        $actor = $request->user();
        app(MedicationRecordAccess::class)->client($actor, $client);
        $data = $request->validate(['request_uuid' => ['required', 'uuid']]);
        $capability = $command === 'check-driver' ? 'medications.administer.record' : 'medications.orders.manage';
        $witnessIds = $command === 'start-driver' && $request->integer('witnessed_by') > 0 ? [$request->integer('witnessed_by')] : [];
        $result = DB::transaction(fn () => app(MedicationGovernanceScopeService::class)->forClient($actor, $client, $capability, function (Client $person, User $lockedActor) use ($request, $command, $data) {
            app(MedicationRecordAccess::class)->assertReadable($lockedActor, $person);
            $scope = 'p02-clinical:'.$person->id.':'.$lockedActor->id.':'.$command;
            $fingerprint = hash('sha256', json_encode($request->except('_token'), JSON_THROW_ON_ERROR));
            $receipt = MedicationIdempotencyResult::query()->where('scope', $scope)->where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->response_payload['fingerprint'], $fingerprint), 409, 'This request was used for a different change.');
                return $receipt->response_payload['result'];
            }
            $controlled = false;
            if (str_contains($command, 'inr')) {
                $valid = $request->validate([
                    'record_id' => [$command === 'inr' ? 'nullable' : 'required', 'integer', 'min:1'],
                    'client_medication_id' => [$command === 'link-inr' ? 'required' : 'nullable', 'integer', 'min:1'],
                    'unlinked_reason' => [$command === 'inr' ? 'required_without:client_medication_id' : 'nullable', 'nullable', 'string', 'max:2000'],
                    'reason' => [$command === 'disable-inr' ? 'required' : 'nullable', 'string', 'max:2000'],
                ]);
                $med = isset($valid['client_medication_id']) ? ClientMedication::query()->where('client_id', $person->id)->whereKey($valid['client_medication_id'])->lockForUpdate()->firstOrFail() : null;
                if ($med) abort_if($med->controlled_drug && ! $lockedActor->canDo('medications.controlled.view'), 404);
                $record = $command === 'inr' ? new ClientInrRecord(['client_id' => $person->id, 'recorded_by' => $lockedActor->id]) : ClientInrRecord::query()->where('client_id', $person->id)->whereKey($valid['record_id'])->lockForUpdate()->firstOrFail();
                if ($record->client_medication_id) {
                    $linked = ClientMedication::withTrashed()->where('client_id', $person->id)->whereKey($record->client_medication_id)->firstOrFail();
                    abort_if($linked->controlled_drug && ! $lockedActor->canDo('medications.controlled.view'), 404);
                    $controlled = (bool) $linked->controlled_drug;
                }
                if ($command === 'disable-inr') {
                    abort_if($record->disabled_at, 409, 'This result is already marked as entered in error.');
                    $record->forceFill(['disabled_at' => now(), 'disabled_by' => $lockedActor->id, 'disabled_reason' => $valid['reason']]);
                } elseif ($command === 'link-inr') {
                    abort_if($record->disabled_at || $record->client_medication_id !== null, 409, 'Only an active unlinked result can be linked.');
                    $record->forceFill(['client_medication_id' => $med->id, 'linked_at' => now(), 'linked_by' => $lockedActor->id]);
                    $controlled = (bool) $med->controlled_drug;
                } else {
                    $clinical = $request->validate([
                        'inr_value' => ['required', 'numeric', 'min:0.5', 'max:20'], 'tested_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Pacific/Auckland')->toDateString()],
                        'target_range_low' => ['nullable', 'numeric', 'min:0.5', 'max:20'], 'target_range_high' => ['nullable', 'numeric', 'min:0.5', 'max:20', 'gte:target_range_low'],
                        'dose_mg' => ['nullable', 'numeric', 'min:0', 'max:999.99'], 'next_test_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tested_on'],
                        'instruction' => ['required', 'string', 'max:2000'], 'instruction_source' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
                    ]);
                    $record->fill($clinical)->forceFill(['client_medication_id' => $med?->id, 'unlinked_reason' => $med ? null : $valid['unlinked_reason']]);
                    $controlled = (bool) $med?->controlled_drug;
                }
                $record->saveOrFail();
                $id = (string) $record->id; $type = 'inr_record';
            } else {
                $legacy = app(EmarController::class);
                if ($command === 'start-driver') {
                    $legacy->storeSyringeDriver($request, $person);
                    $driver = $person->syringeDrivers()->latest('id')->firstOrFail();
                } else {
                    $request->validate(['record_id' => ['required', 'integer', 'min:1']]);
                    $driver = MedicationSyringeDriver::query()->where('client_id', $person->id)->where('site_id', $person->site_id)->findOrFail($request->integer('record_id'));
                    $command === 'check-driver' ? $legacy->addSyringeDriverCheck($request, $driver) : $legacy->completeSyringeDriver($request, $driver);
                }
                $controlled = ClientMedication::withTrashed()->where('client_id', $person->id)->whereIn('id', collect($driver->contents)->pluck('client_medication_id'))->where('controlled_drug', true)->exists();
                $id = (string) $driver->id; $type = 'syringe_driver';
            }
            $result = ['saved' => true, 'id' => $id];
            MedicationIdempotencyResult::query()->create(['scope' => $scope, 'request_uuid' => $data['request_uuid'], 'response_payload' => ['fingerprint' => $fingerprint, 'result' => $result], 'expires_at' => now()->addDays(30)]);
            app(MedicationEventRecorder::class)->append(new MedicationEventData(siteId: (int) $person->site_id, kind: 'clinical.'.$command, subjectType: $type, subjectId: $id, actorId: $lockedActor->id, occurredAt: CarbonImmutable::now(), summary: 'Clinical medication record saved', clientId: (int) $person->id, controlled: $controlled));
            return $result;
        }, authorizationUserIds: $witnessIds), 5);
        return $request->header('X-Inertia') ? redirect()->back()->with('success', 'Clinical medication record saved.') : response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
