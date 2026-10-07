<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockCountRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\PharmacyConnect\PharmacyDispatchGuard;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\Medication\Stock\StockReadPayload;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\PharmacySupplyRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

final class MedicationStockController extends Controller
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly MedicationStockService $stock,
        private readonly StockReadPayload $read,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('emar/stock/StockHub', $this->read->page($request));
    }

    public function detail(Request $request, int $medication)
    {
        return response()->json($this->read->detail($request->user(), $medication))->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request)
    {
        // Existing scope only. The newly approved receive grant is held by
        // automatic approval review and is not silently added here.
        abort_unless($request->user()->canDo('medications.stock.update'), 403);
        abort_unless(config('medications.stock_lots_enabled', false), 422, 'Stock pack workflows are disabled for this installation.');
        $action = $request->string('action')->toString();
        $rules = $this->rules($action);
        $data = $request->validate([
            'action' => 'required|string', 'client_medication_id' => 'required|integer|min:1',
            'request_uuid' => 'required|uuid', 'witnessed_by' => 'nullable|integer|min:1', 'witness_credential' => 'nullable|string|max:255', ...$rules,
        ]);

        return DB::transaction(fn () => $this->scope->forMedication($request->user(), $data['client_medication_id'], 'medications.stock.update',
            function (Client $client, ClientMedication $med, User $actor, Collection $lockedUsers) use ($data, $action) {
                $this->access->assertReadable($actor, $client);
                abort_if($med->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
                // Controlled receipt uses P07's witness + ledger transaction.
                abort_if($med->controlled_drug && ! in_array($action, ['initialise', 'receive', 'order', 'order_update'], true), 422, 'Use the witnessed controlled-drug workflow for this action.');
                $med->setRelation('client', $client);
                if ($med->controlled_drug && in_array($action, ['initialise', 'receive'], true)) {
                    abort_unless($actor->canDo('medications.controlled.record'), 403);
                    app(ControlledRegisterService::class)->assertPresent($actor, (int) $client->site_id, true);
                    $this->scope->confirmedControlledWitness($actor, $client, (int) ($data['witnessed_by'] ?? 0), $data['witness_credential'] ?? null, lockedUsers: $lockedUsers);
                }
                $binding = $data;
                unset($binding['witness_credential']);
                $fingerprint = hash('sha256', json_encode(['actor' => $actor->id, 'data' => $binding], JSON_THROW_ON_ERROR));
                $replay = ['client_request_uuid' => $data['request_uuid']];
                $stored = $this->scope->idempotencyResult('emar-p06-command', $replay, $fingerprint, durable: true);
                if ($stored) {
                    return response()->json([...$stored, 'duplicate' => true]);
                }
                $auditEvents = [];
                $result = $this->execute($med, $actor, $action, $data, $lockedUsers, $auditEvents);
                $payload = ['success' => true, ...$result];
                $this->scope->rememberIdempotencyResult('emar-p06-command', $replay, $payload, $fingerprint, durable: true);
                if ($action === 'count') {
                    $count = MedicationStockCountRecord::findOrFail($result['count_id']);
                    if ($count->state === 'needs_review') {
                        app(MedicationFollowupService::class)->ensureForSource(
                            'stock-discrepancy', $count->id, $client, $med, null, null, null,
                            ['count_id' => $count->id, 'source_url' => '/emar/stock/packs?view=counts&count_id='.$count->id],
                        );
                    }
                } elseif ($action === 'count_review') {
                    // All source locks, writes and the durable receipt are done.
                    // Collect completion audit before appending this whole batch.
                    app(MedicationFollowupService::class)->completeFromSource(
                        'stock-discrepancy:'.$result['count_id'], $actor, 'count_reviewed',
                        ['count_id' => $result['count_id']], $auditEvents,
                    );
                }
                $subjectKey = array_key_first($result);
                $auditEvents[] = new MedicationEventData(
                    siteId: (int) $client->site_id, kind: 'stock.'.$action,
                    subjectType: match ($subjectKey) {
                        'lot_id' => 'stock_lot', 'order_id' => 'pharmacy_supply', 'count_id' => 'stock_count', 'movement_id' => 'stock_movement', default => 'stock'
                    },
                    subjectId: (string) ($result[$subjectKey] ?? $med->id), actorId: $actor->id, occurredAt: CarbonImmutable::now('UTC'),
                    summary: match ($action) {
                        'receive' => 'Stock received', 'order' => 'Pharmacy supply record created', 'order_update' => 'Pharmacy supply evidence updated', 'count' => 'Stock counted', 'count_review' => 'Stock count reviewed', 'initialise' => 'Recorded stock balance carried forward', default => 'Stock movement recorded'
                    },
                    facts: ['request_uuid' => $data['request_uuid'], 'client_medication_id' => $med->id, ...$result], clientId: $client->id, controlled: (bool) $med->controlled_drug,
                );
                app(MedicationEventRecorder::class)->appendMany($auditEvents);

                return response()->json($payload);
            }, authorizationUserIds: array_values(array_filter([(int) ($data['witnessed_by'] ?? 0)]))), 5);
    }

    private function execute(ClientMedication $med, User $actor, string $action, array $data, Collection $lockedUsers, array &$auditEvents): array
    {
        if ($action === 'order') {
            abort_unless($med->active && $med->state === 'active', 422, 'This medicine is no longer active.');
            $order = MedicationPharmacyOrder::create([
                'client_id' => $med->client_id, 'client_medication_id' => $med->id, 'pharmacy_name' => $data['pharmacy_name'],
                'quantity_ordered' => $data['quantity_ordered'], 'needed_by' => $data['needed_by'],
                'order_notes' => $data['order_notes'] ?? null, 'ordered_by' => $actor->id, 'status' => 'draft',
            ]);
            AuditLogger::logOrFail('medications.stock.supply_order_created', $order, ['actor_id' => $actor->id, 'request_uuid' => $data['request_uuid']]);

            return ['order_id' => $order->id];
        }
        if ($action === 'order_update') {
            return $this->updateOrder($med, $actor, $data, $auditEvents);
        }
        $stock = ClientMedicationStock::where('client_medication_id', $med->id)->lockForUpdate()->first();
        if (! $stock) {
            if ($action !== 'receive' || trim((string) ($data['unit'] ?? '')) === '') {
                throw ValidationException::withMessages(['unit' => 'Receive the first pack with the counted unit shown on its label. Do not assume a stock unit.']);
            }
            $stock = ClientMedicationStock::create(['client_medication_id' => $med->id, 'on_hand' => '0.00', 'unit' => trim($data['unit'])]);
            $stock->forceFill(['lots_started_at' => now()])->saveFromPackLedger();
        } elseif ($action === 'receive' && isset($data['unit']) && trim($data['unit']) !== $stock->unit) {
            throw ValidationException::withMessages(['unit' => 'The unit must match this stock record. Ask the house lead to resolve a different pack unit.']);
        }
        $stock->setRelation('medication', $med);
        if ($action === 'initialise') {
            abort_unless(config('medications.stock_lots_enabled', false), 422, 'Pack tracking is disabled for this installation.');
            if ($med->controlled_drug) {
                abort_unless($actor->canDo('medications.controlled.record'), 403);
                app(ControlledRegisterService::class)->assertPresent($actor, (int) $med->client->site_id, true);
                $this->scope->confirmedControlledWitness($actor, $med->client, (int) ($data['witnessed_by'] ?? 0), $data['witness_credential'] ?? null, lockedUsers: $lockedUsers);
            }
            $this->stock->startLots($stock, $actor, $data['request_uuid']);

            return ['stock_id' => $stock->id];
        }
        if ($action === 'receive') {
            $order = empty($data['pharmacy_order_id']) ? null : MedicationPharmacyOrder::where('client_id', $med->client_id)
                ->where('client_medication_id', $med->id)->lockForUpdate()->findOrFail($data['pharmacy_order_id']);
            if ($med->controlled_drug) {
                abort_unless($actor->canDo('medications.controlled.record'), 403);
                $quantity = isset($data['packs']) ? collect($data['packs'])->reduce(fn ($sum, $pack) => Qty::add($sum, $pack['quantity']), '0.00') : $data['quantity'];
                $result = [];
                app(ControlledRegisterService::class)->recordReceipt(
                    $actor, $med->client, $med, $stock,
                    [...$data, 'quantity' => $quantity], $lockedUsers,
                    function ($entry) use ($stock, $actor, $data, $order, &$result): void {
                        $result = $this->stock->controlledDeliveryAfterRegister($stock, $actor, $data, $entry, $order);
                    },
                );

                return $result;
            }

            return $this->stock->receiveDelivery($stock, $actor, $data, $order);
        }
        if ($action === 'count') {
            return ['count_id' => $this->stock->count($stock, $actor, $data)->id];
        }
        if ($action === 'count_review') {
            $count = MedicationStockCountRecord::where('client_medication_stock_id', $stock->id)->lockForUpdate()->findOrFail($data['count_id']);
            $this->stock->reviewCount($stock, $count, $actor, $data['reason'], $data['request_uuid']);

            return ['count_id' => $count->id];
        }
        if ($action === 'coming_back') {
            return ['movement_id' => $this->stock->comingBack($stock, $actor, $data)->id];
        }

        return ['movement_id' => $this->stock->move($stock, $actor, $action === 'going_out' ? [...$data, 'kind' => 'going_out'] : $data)->id];
    }

    private function updateOrder(ClientMedication $med, User $actor, array $data, array &$auditEvents): array
    {
        $order = MedicationPharmacyOrder::where('client_id', $med->client_id)->where('client_medication_id', $med->id)->lockForUpdate()->findOrFail($data['order_id']);
        try {
            PharmacySupplyRules::assertEditable($order->status);
            $next = $data['next'];
            if (in_array($next, ['cancelled', 'closed_short'], true)) {
                PharmacySupplyRules::assertClosure($order->status, $next, $data['reason'] ?? '');
                app(PharmacyDispatchGuard::class)->localClosure($order, $med->client, $med, $actor, $auditEvents);
                $order->forceFill(['status' => $next, 'closed_by' => $actor->id, 'closed_at' => now(), 'closure_reason' => $data['reason']])->save();
            } elseif ($next === 'contacted') {
                app(PharmacyDispatchGuard::class)->assertManualContact($order);
                if ($order->status !== 'draft' || empty($data['communication_method']) || trim((string) ($data['communication_reference'] ?? '')) === '') {
                    throw new InvalidArgumentException('Record how the pharmacy was contacted and the source or reference. Saving here does not send this order.');
                }
                $order->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'communication_method' => $data['communication_method'],
                    'communication_reference' => $data['communication_reference'], 'communication_recorded_by' => $actor->id, 'communication_recorded_at' => now()])->save();
            } else {
                if (! in_array($order->status, ['submitted', 'confirmed'], true) || empty($data['quantity_dispensed']) || empty($data['expected_delivery'])
                    || Qty::greaterThan($data['quantity_dispensed'], $order->quantity_ordered)) {
                    throw new InvalidArgumentException('Enter what the pharmacy dispensed and when it is due. The quantity cannot exceed the order.');
                }
                $order->forceFill(['status' => 'dispensed', 'dispensed_at' => now(), 'quantity_dispensed' => $data['quantity_dispensed'],
                    'batch_number' => $data['batch_number'] ?? null, 'batch_expiry' => $data['batch_expiry'] ?? null, 'expected_delivery' => $data['expected_delivery']])->save();
            }
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['next' => $error->getMessage()]);
        }
        AuditLogger::logOrFail('medications.stock.supply_order_updated', $order, ['actor_id' => $actor->id, 'action' => $data['next']]);

        return ['order_id' => $order->id];
    }

    private function rules(string $action): array
    {
        $positive = ['required', 'numeric', Qty::VALIDATION_RULE, 'min:0.01', Qty::DECIMAL_12_2_MAX_RULE];
        $nonNegative = ['required', 'numeric', Qty::VALIDATION_RULE, 'min:0', Qty::DECIMAL_12_2_MAX_RULE];
        $reason = ['reason' => 'required|string|max:2000', 'notes' => 'nullable|string|max:2000'];

        return match ($action) {
            'initialise' => ['confirm_balance' => 'accepted'],
            'receive' => [
                'unit' => 'nullable|string|max:50',
                'packs' => 'required_without:quantity|array|min:1|max:25',
                'quantity' => [...array_diff($positive, ['required']), 'required_without:packs', 'prohibits:packs'],
                'batch_number' => 'nullable|string|max:100', 'batch_not_printed' => 'required_without:packs|boolean',
                'expiry_month' => 'nullable|string|max:7', 'expiry_not_printed' => 'required_without:packs|boolean',
                'packs.*' => 'required|array:quantity,batch_number,batch_not_printed,expiry_month,expiry_not_printed,short_expiry_reason',
                'packs.*.quantity' => $positive, 'packs.*.batch_number' => 'nullable|string|max:100', 'packs.*.batch_not_printed' => 'required|boolean',
                'packs.*.expiry_month' => 'nullable|string|max:7', 'packs.*.expiry_not_printed' => 'required|boolean',
                'packs.*.short_expiry_reason' => 'nullable|string|max:2000',
                'delivery_outcome' => 'nullable|in:still_to_come,closed_short', 'closure_reason' => 'nullable|string|max:2000',
                'short_expiry_reason' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:2000', 'label_checked' => 'accepted',
                'source' => 'required|in:pharmacy,family,hospital,respite,other', 'source_reference' => 'required|string|max:255',
                'pharmacy_order_id' => 'nullable|integer|min:1',
            ],
            'move' => ['lot_id' => 'required|integer|min:1', 'kind' => 'required|in:returned_pharmacy,removed_expired,damaged,quarantined', 'quantity' => $positive, ...$reason],
            'going_out' => ['lot_id' => 'required|integer|min:1', 'quantity' => $positive, ...$reason],
            'coming_back' => ['return_of_id' => 'required|integer|min:1', 'quantity' => $nonNegative, 'used_away' => $nonNegative, ...$reason],
            'count' => ['coverage_start' => 'nullable|date', 'coverage_end' => 'nullable|date', 'covered_paper_entry_ids' => 'nullable|array|max:100', 'covered_paper_entry_ids.*' => 'integer|min:1', 'lines' => 'present|array', 'confirm_empty' => 'nullable|boolean', 'lines.*.lot_id' => 'required|integer|min:1', 'lines.*.revision' => 'required|integer|min:0', 'lines.*.quantity' => $nonNegative, 'reason' => 'nullable|string|max:2000'],
            'count_review' => ['count_id' => 'required|integer|min:1', ...$reason],
            'order' => ['pharmacy_name' => 'required|string|max:255', 'quantity_ordered' => 'required|integer|min:1|max:100000', 'needed_by' => 'required|date_format:Y-m-d', 'order_notes' => 'nullable|string|max:2000'],
            'order_update' => [
                'order_id' => 'required|integer|min:1', 'next' => 'required|in:contacted,dispensed,cancelled,closed_short',
                'communication_method' => 'nullable|in:phone,email,in_person,secure_message', 'communication_reference' => 'nullable|string|max:2000',
                'quantity_dispensed' => ['nullable', 'numeric', Qty::VALIDATION_RULE, 'min:0.01', 'max:100000'],
                'expected_delivery' => 'nullable|date_format:Y-m-d', 'batch_number' => 'nullable|string|max:100', 'batch_expiry' => 'nullable|date_format:Y-m-d',
                'reason' => 'nullable|string|max:2000',
            ],
            default => throw ValidationException::withMessages(['action' => 'Choose a stock action.']),
        };
    }
}
