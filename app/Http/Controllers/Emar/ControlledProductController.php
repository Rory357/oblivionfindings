<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationDestruction;
use App\Services\Medication\Controlled\ControlledProductPayload;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\MedicationRecordAccess;
use App\Support\Medication\MedicationStockQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ControlledProductController extends Controller
{
    public function destructions(Request $request)
    {
        return app(EmarController::class)->destructions($request);
    }

    public function losses(Request $request)
    {
        return $this->redirectRead($request, 'losses');
    }

    /** Old URLs use the same validated command; old unwitnessed forms cannot bypass it. */
    public function legacy(Request $request)
    {
        $action = match ($request->route()->getName()) {
            'emar.controlled.entries.store' => 'movement',
            'emar.controlled.balance_check.store' => 'count',
            'emar.controlled.discrepancies.resolve', 'clients.medical.controlled_discrepancies.close' => 'resolve',
            'emar.destructions.store' => 'destruction',
            'emar.destructions.void' => 'destruction_void',
            'emar.cd_loss.store' => 'loss_report',
            'emar.cd_loss.investigate' => 'loss_note',
            'emar.cd_loss.resolve' => 'loss_close',
            default => abort(404),
        };
        $parameter = match ($action) {
            'resolve' => 'discrepancy', 'destruction_void' => 'destruction', 'loss_note', 'loss_close' => 'report', default => null
        };
        if ($parameter !== null) {
            $target = $request->route($parameter);
            $id = $target instanceof Model ? $target->getKey() : (int) $target;
            $record = match ($parameter) {
                'discrepancy' => ClientControlledDrugDiscrepancy::findOrFail($id),
                'destruction' => MedicationDestruction::findOrFail($id),
                'report' => ControlledDrugLossReport::findOrFail($id),
            };
            $this->access->client($request->user(), (int) $record->client_id);
            $client = $request->route('client');
            abort_if($client !== null && (int) ($client instanceof Model ? $client->getKey() : $client) !== (int) $record->client_id, 404);
            abort_if($request->filled('client_medication_id') && $request->integer('client_medication_id') !== (int) $record->client_medication_id, 404);
            $request->merge(['client_medication_id' => $record->client_medication_id, 'target_id' => $record->id]);
        }
        if ($action === 'destruction_void') {
            abort_if($record->is_controlled_drug && ! $request->user()->canDo('medications.controlled.view'), 404);
            if (! $record->is_controlled_drug || $record->client_medication_id === null || ! $request->filled('client_request_uuid')) {
                return app(EmarController::class)->voidDestruction($request, $record);
            }
            $request->merge(['notes' => $request->input('notes', $request->input('void_reason'))]);
        }
        // Canonical read/scope denial precedes clinical form validation; all
        // writes still recheck the same records under the governing locks.
        if ($request->integer('client_medication_id') > 0) {
            $medicine = ClientMedication::withTrashed()->findOrFail($request->integer('client_medication_id'));
            $client = $this->access->client($request->user(), (int) $medicine->client_id);
            abort_if($medicine->controlled_drug && ! $request->user()->canDo('medications.controlled.view'), 404);
            abort_if($request->filled('client_id') && $request->integer('client_id') !== (int) $client->id, 404);
            abort_if($request->filled('site_id') && $request->integer('site_id') !== (int) $client->site_id, 404);
            abort_if($action !== 'destruction' && $request->filled('medication_name') && $request->input('medication_name') !== $medicine->name, 404);
        }
        if ($action === 'destruction') {
            $medicine = ClientMedication::query()->find($request->integer('client_medication_id'));
            if ($medicine && ! $medicine->controlled_drug) {
                return app(EmarController::class)->storeDestruction($request, ordinaryOnly: true);
            }
        }
        if ($action === 'movement') {
            abort_unless(in_array($request->input('movement_type'), ['going_out', 'coming_back', 'breakage', 'spillage'], true), 422, 'Choose a witnessed movement in the controlled register.');
        }

        $request->attributes->set('controlled_legacy_command', true);

        try {
            $response = $this->action($request, $action);
        } catch (HttpExceptionInterface $exception) {
            if (! $request->expectsJson() || $exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return response()->json(['message' => $exception->getMessage(), 'sync' => ['status' => 'conflict', 'duplicate' => false]], 409);
        }
        if (! $request->expectsJson()) {
            return redirect()->back()->with('success', $response->getData(true)['message']);
        }

        return $response;
    }

    public function __construct(
        private readonly ControlledProductPayload $payload,
        private readonly ControlledRegisterService $register,
        private readonly MedicationRecordAccess $access,
    ) {}

    public function index(Request $request)
    {
        $product = $this->read($request);

        return Inertia::render('emar/ControlledRegister', [
            'product' => $product,
            'site_brand_colour' => $product['site_brand_colour'],
        ]);
    }

    public function overrides(Request $request)
    {
        return Inertia::render('emar/WitnessOverrides', ['product' => $this->read($request)]);
    }

    public function product(Request $request)
    {
        return response()->json($this->read($request))->header('Cache-Control', 'private, no-store');
    }

    private function read(Request $request): array
    {
        $filters = $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'client_medication_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'], 'date' => ['nullable', 'date_format:Y-m-d']]);

        return $this->payload->forActor($request->user(), isset($filters['site_id']) ? (int) $filters['site_id'] : null, isset($filters['client_medication_id']) ? (int) $filters['client_medication_id'] : null, isset($filters['client_id']) ? (int) $filters['client_id'] : null, $filters['date'] ?? null);
    }

    private function redirectRead(Request $request, string $view)
    {
        $product = $this->read($request);

        return redirect('/emar/controlled?'.http_build_query(array_filter(['view' => $view, ...$product['filters']], fn ($value) => $value !== null)));
    }

    public function action(Request $request, string $action)
    {
        // Witnessed cupboard actions are deliberately online-only; no PIN or
        // stock assertion is retained in the device's offline replay queue.
        abort_if($request->boolean('queued_offline'), 422, 'Controlled checks need a connection. Your entered values have been kept.');
        $needsWitness = in_array($action, ['count', 'movement', 'void', 'loss_report', 'destruction'], true)
            || ($action === 'resolve' && $request->input('outcome') !== 'escalate');
        $quantity = ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE];
        $input = $request->validate([
            'client_medication_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'],
            'medication_name' => ['nullable', 'string', 'max:255'], 'entry_type' => ['nullable', 'string', 'max:50'],
            'unit' => ['nullable', 'string', 'max:50'], 'batch_number' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'], 'cd_schedule' => ['nullable', 'integer'],
            'on_hand_before' => [Rule::requiredIf($request->attributes->get('controlled_legacy_command') && $action === 'movement'), ...$quantity],
            'on_hand_after' => [Rule::requiredIf($request->attributes->get('controlled_legacy_command') && $action === 'movement'), ...$quantity],
            'captured_offline_at' => ['prohibited'], 'origin_device_id' => ['prohibited'], 'initialize_stock' => ['prohibited'],
            'accountable_officer_name' => ['nullable', 'string', 'max:255'], 'regulator_name' => ['nullable', 'string', 'max:255'],
            'client_request_uuid' => ['required', 'uuid'],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'entry_id' => ['nullable', 'integer', 'min:1'],
            'administration_id' => ['nullable', 'integer', 'min:1'],
            'counted_entry_id' => ['nullable', 'integer', 'min:1'],
            'expected_entry_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_balance' => [Rule::requiredIf(in_array($action, ['count', 'movement', 'void', 'resolve', 'loss_report', 'destruction'], true)), ...$quantity],
            'actual_balance' => [Rule::requiredIf(in_array($action, ['count', 'movement'], true)), ...$quantity], 'recount_balance' => $quantity,
            'quantity' => $quantity, 'correction_quantity' => $quantity,
            'direction' => ['nullable', 'in:in,out'], 'correction_direction' => ['nullable', 'in:in,out'],
            'movement_type' => ['nullable', 'in:going_out,coming_back,breakage,spillage'],
            'witnessed_by' => [Rule::requiredIf($needsWitness), 'nullable', 'integer', 'min:1'], 'witness_id' => ['nullable', 'integer', 'min:1'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'second_witness_id' => ['nullable', 'integer', 'min:1'], 'second_witness_credential' => ['nullable', 'string', 'max:255'],
            'outcome' => ['nullable', 'in:recount,recording,found,loss,escalate'],
            'notes' => ['nullable', 'string', 'max:5000'], 'immediate_action_taken' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:2000'], 'nz_class' => ['nullable', 'in:A,B,C'],
            'purpose' => ['nullable', 'in:count,movement,dose'], 'response' => ['nullable', 'in:on_my_way,cant_come'],
            'suspected_theft' => ['nullable', 'boolean'],
            'ready_to_close' => ['nullable', 'boolean'], 'notifications_checked' => [Rule::requiredIf($action === 'loss_close'), 'boolean'],
            'resolution_outcome' => [Rule::requiredIf($action === 'loss_close'), 'in:accidental,unexplained,theft'],
            'method' => ['nullable', 'in:pharmacy_return,denaturing'],
            'reason' => ['nullable', 'in:expired,ceased,contaminated,damaged,deceased,discharged,surplus'],
            'authority' => ['nullable', 'in:police,regulator,pharmacy'], 'reference' => ['nullable', 'string', 'max:255'],
            'reported_to_police' => ['nullable', 'boolean'], 'police_reference' => ['nullable', 'string', 'max:255'],
            'reported_to_regulator' => ['nullable', 'boolean'], 'regulator_reference' => ['nullable', 'string', 'max:255'],
            'notified_at' => ['nullable', 'string', 'max:35'], 'discovered_at' => ['nullable', 'string', 'max:35'],
            'authorised_by_name' => ['nullable', 'string', 'max:255'], 'authorised_by_registration' => ['nullable', 'string', 'max:255'],
            'pharmacist_name' => ['nullable', 'string', 'max:255'], 'pharmacist_registration' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'string', 'max:35'], 'starts_at' => ['nullable', 'string', 'max:35'], 'expires_at' => ['nullable', 'string', 'max:35'],
            'decision' => ['nullable', 'in:approved,declined'], 'medicine_ids' => ['nullable', 'array', 'max:100'],
            'medicine_ids.*' => ['integer', 'min:1'], 'timezone' => ['nullable', 'in:Pacific/Auckland'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $photo = $request->file('photo');
        unset($input['photo']);
        if ($photo !== null) {
            abort_unless($action === 'destruction', 422, 'A photo belongs to a destruction record.');
            $input['photo_sha256'] = hash_file('sha256', $photo->getRealPath());
            $input['photo_upload'] = $photo;
        }
        $result = $this->register->perform($request->user(), $action, $input);
        if ($request->attributes->get('controlled_legacy_command')) {
            $duplicate = $this->register->lastRequestWasReplay();
            $result['sync'] = ['status' => $duplicate ? 'duplicate' : 'saved', 'duplicate' => $duplicate];
            if (isset($result['entry_id'])) {
                $result['entry'] = ['id' => (int) $result['entry_id']];
            }
            if (isset($result['discrepancy_id'])) {
                $result['discrepancy'] = ['id' => (int) $result['discrepancy_id']];
            }
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function photo(Request $request, MedicationDestruction $destruction)
    {
        abort_unless($request->user()->canDo('medications.controlled.view'), 403);
        $medication = ClientMedication::withTrashed()->with('client')->findOrFail((int) $destruction->client_medication_id);
        $client = $this->access->client($request->user(), (int) $medication->client_id);
        $medication->setRelation('client', $client);
        abort_unless((int) $destruction->client_id === (int) $medication->client_id && $destruction->is_controlled_drug && $destruction->photo_path, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($destruction->photo_path), 404);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('download')
            ? $disk->download($destruction->photo_path, 'destruction-photo.'.pathinfo($destruction->photo_path, PATHINFO_EXTENSION), $headers)
            : $disk->response($destruction->photo_path, null, $headers);
    }
}
